<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            if (! $request->is('api/v1/*') || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }
            $status = $e instanceof \Illuminate\Auth\AuthenticationException ? 401 : ($e instanceof \Illuminate\Validation\ValidationException ? 422 : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500));
            $code = match ($status) {
                422 => 'VALIDATION_FAILED', 404 => 'RESOURCE_NOT_FOUND',
                429 => 'RATE_LIMITED', 413 => 'PAYLOAD_TOO_LARGE',
                500 => 'INTERNAL_ERROR', default => 'HTTP_ERROR',
                401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN',
            };
            $headers = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getHeaders() : [];

            return response()->json(['error' => [
                'code' => $code, 'message' => $status === 422 ? 'Dữ liệu không hợp lệ.' : 'Không thể xử lý yêu cầu agent.',
                'fields' => $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : [],
                'retryable' => $status === 429 || $status >= 500,
            ], 'meta' => \App\Support\AgentApi::meta()], $status, $headers);
        });
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
