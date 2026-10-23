<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AgentApi
{
    public static function meta(): array
    {
        return ['request_id' => request()->attributes->get('agent_request_id', (string) Str::uuid()), 'server_time' => now()->toISOString()];
    }

    public static function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => array_merge(self::meta(), $meta)], $status);
    }

    public static function fail(string $code, string $message, int $status = 409, bool $retryable = false, array $fields = []): never
    {
        throw new HttpResponseException(response()->json([
            'error' => compact('code', 'message', 'fields', 'retryable'), 'meta' => self::meta(),
        ], $status));
    }

    public static function hash(mixed $value): string
    {
        $normalize = function ($item) use (&$normalize) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
