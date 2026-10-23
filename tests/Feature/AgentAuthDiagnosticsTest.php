<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentAuthDiagnosticsTest extends TestCase
{
    public function test_auth_rejection_logs_only_fingerprints_with_request_id(): void
    {
        config(['zalomind.agent_api_key' => 'expected-test-key']);
        Log::spy();
        $requestId = (string) Str::uuid();

        $this->withHeaders([
            'Authorization' => 'Bearer received-test-key',
            'X-Request-Id' => $requestId,
        ])->postJson('/api/v1/agent/commands/claim', ['limit' => 2])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AGENT_KEY_INVALID');

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($requestId) {
            return $message === 'agent_auth_rejected'
                && $context['request_id'] === $requestId
                && $context['path'] === 'api/v1/agent/commands/claim'
                && $context['expected_key_sha256'] === substr(hash('sha256', 'expected-test-key'), 0, 12)
                && $context['received_key_sha256'] === substr(hash('sha256', 'received-test-key'), 0, 12)
                && ! str_contains(json_encode($context), 'expected-test-key')
                && ! str_contains(json_encode($context), 'received-test-key');
        });
    }

    public function test_unavailable_logger_does_not_weaken_authentication(): void
    {
        config(['zalomind.agent_api_key' => 'expected-test-key']);
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('Log unavailable'));

        $this->withToken('invalid-test-key')->getJson('/api/v1/agent/config')
            ->assertUnauthorized()->assertJsonPath('error.code', 'AGENT_KEY_INVALID');
    }
}
