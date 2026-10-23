<?php

namespace App\Http\Middleware;

use App\Support\AgentApi;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AgentAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id');
        $request->attributes->set('agent_request_id', Str::isUuid($requestId ?? '') ? $requestId : (string) Str::uuid());
        try {
            $expected = (string) config('zalomind.agent_api_key');
            $received = (string) $request->bearerToken();
            if ($expected === '' || ! hash_equals($expected, $received)) {
                // Correlate intermittent 401s with the agent log without storing keys.
                // A broken log destination must never change authentication behavior.
                try {
                    Log::warning('agent_auth_rejected', [
                        'request_id' => $request->attributes->get('agent_request_id'),
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'host' => $request->getHost(),
                        'worker_pid' => getmypid(),
                        'config_cached' => app()->configurationIsCached(),
                        'authorization_present' => $request->hasHeader('Authorization'),
                        'expected_key_sha256' => $expected === '' ? 'empty' : substr(hash('sha256', $expected), 0, 12),
                        'received_key_sha256' => $received === '' ? 'empty' : substr(hash('sha256', $received), 0, 12),
                    ]);
                } catch (\Throwable $loggingFailure) {
                    // Keep the response below fail-closed with HTTP 401.
                }
                AgentApi::fail('AGENT_KEY_INVALID', 'Key agent thiếu hoặc không hợp lệ.', 401);
            }
            if (strlen($request->getContent()) > 1048576) {
                AgentApi::fail('PAYLOAD_TOO_LARGE', 'JSON vượt quá 1 MiB.', 413);
            }
            if ($request->getContent() !== '') {
                try {
                    $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    AgentApi::fail('INVALID_JSON', 'JSON không hợp lệ.', 400);
                }
                if (! is_array($body) || (! $request->isMethod('GET') && ! str_starts_with(ltrim($request->getContent()), '{'))) {
                    AgentApi::fail('VALIDATION_FAILED', 'Body phải là JSON object.', 422);
                }
            }

            // One logical agent: serialize mutations using its permanent database row.
            $response = DB::transaction(function () use ($request, $next) {
                $runtime = DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
                if (! $runtime || ! $runtime->enabled) {
                    AgentApi::fail('NODE_DISABLED', 'Agent đã bị tắt.', 403);
                }
                $request->attributes->set('agent_runtime', $runtime);
                $idempotent = $request->isMethod('POST') && preg_match('#/(commands/claim|commands/[^/]+/result|snapshots|snapshots/[^/]+/complete)$#', $request->path());
                $scope = null;
                if ($idempotent) {
                    $key = $request->header('Idempotency-Key');
                    if (! is_string($key) || ! Str::isUuid($key)) {
                        AgentApi::fail('VALIDATION_FAILED', 'Idempotency-Key phải là UUID.', 422);
                    }
                    $scope = hash('sha256', 'agent_default:'.$request->method().':'.$request->path().':'.$key);
                    $stored = DB::table('agent_idempotency')->where('scope', $scope)->first();
                    $bodyHash = AgentApi::hash($request->json()->all());
                    if ($stored) {
                        if (! hash_equals($stored->body_hash, $bodyHash)) {
                            AgentApi::fail('IDEMPOTENCY_CONFLICT', 'Khóa đã được dùng với dữ liệu khác.');
                        }
                        $payload = json_decode(Crypt::decryptString(json_decode($stored->response, true)), true);
                        $payload['meta'] = AgentApi::meta();
                        if (str_ends_with($request->path(), '/result')) {
                            $payload['data']['duplicate'] = true;
                        }

                        return response()->json($payload, $stored->status_code);
                    }
                }
                $response = $next($request);
                // Routing may render controller exceptions before returning to middleware.
                // Roll back writes even when the exception has become an HTTP response.
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }
                if ($scope && $response->isSuccessful()) {
                    DB::table('agent_idempotency')->insert([
                        'scope' => $scope, 'body_hash' => $bodyHash, 'status_code' => $response->getStatusCode(),
                        'response' => json_encode(Crypt::encryptString($response->getContent())), 'created_at' => now(),
                    ]);
                }

                return $response;
            }, 3);
        } catch (ValidationException $e) {
            $response = response()->json(['error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Dữ liệu không hợp lệ.', 'fields' => $e->errors(), 'retryable' => false], 'meta' => AgentApi::meta()], 422);
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
            $response = response()->json(['error' => ['code' => $status === 404 ? 'RESOURCE_NOT_FOUND' : 'HTTP_ERROR', 'message' => 'Không thể xử lý yêu cầu.', 'fields' => [], 'retryable' => false], 'meta' => AgentApi::meta()], $status);
        } catch (\Throwable $e) {
            report($e);
            $response = response()->json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Lỗi xử lý agent.', 'fields' => [], 'retryable' => true], 'meta' => AgentApi::meta()], 500);
        }

        $response->headers->set('X-Request-Id', $request->attributes->get('agent_request_id'));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
