<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zalomind.agent_api_key' => 'test-agent-key']);
        $this->withHeaders(['Authorization' => 'Bearer test-agent-key']);
    }

    private function profile(): AgentProfile
    {
        return AgentProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Test profile']);
    }

    private function heartbeat(): void
    {
        $this->postJson('/api/v1/agent/heartbeat', [
            'app_version' => '1.0', 'protocol_version' => '1',
            'capabilities' => ['message.send', 'profile.login_qr', 'contacts.sync'],
            'capacity' => 2, 'running_command_ids' => [],
            'health' => ['status' => 'healthy', 'queue_depth' => 0],
        ])->assertOk()->assertJsonPath('data.accepted', true);
    }

    private function command(?AgentProfile $profile = null, string $type = 'message.send'): AgentCommand
    {
        $profile ??= $this->profile();
        if (! in_array($type, ['profile.login_qr', 'profile.logout'], true)) {
            $profile->update(['status' => 'connected']);
        }

        return AgentCommand::create(['profile_id' => $profile->id, 'type' => $type, 'payload' => ['recipient' => ['type' => 'user', 'id' => '123'], 'content' => ['type' => 'text', 'text' => 'Xin chào']]]);
    }

    private function postIdempotent(string $path, array $body, ?string $key = null)
    {
        return $this->postJson('/api/v1/agent/'.$path, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function claim(): array
    {
        $this->heartbeat();

        return $this->postIdempotent('commands/claim', ['limit' => 2])->assertOk()->json('data');
    }

    private function leaseBody(array $command): array
    {
        return array_intersect_key($command, array_flip(['lease_token', 'attempt', 'assignment_version']));
    }

    public function test_config_authentication_fails_closed_and_agent_cannot_use_user_api(): void
    {
        $this->getJson('/api/v1/agent/config')->assertOk()->assertJsonPath('data.lease_seconds', 43200)->assertJsonPath('data.execution_managed_by', 'app')->assertJsonMissingPath('data.node_id');
        $this->withToken('wrong')->getJson('/api/v1/agent/config')->assertUnauthorized()->assertJsonPath('error.code', 'AGENT_KEY_INVALID');
        config(['zalomind.agent_api_key' => '']);
        $this->getJson('/api/v1/agent/config')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_heartbeat_and_profile_listing_are_persisted(): void
    {
        $profile = $this->profile();
        $this->heartbeat();
        $this->assertNotNull(DB::table('agent_runtime')->value('last_heartbeat_at'));
        $this->getJson('/api/v1/agent/profiles?limit=1')->assertOk()->assertJsonPath('data.0.id', $profile->id)->assertJsonMissingPath('data.0.user_id');
        $this->getJson('/api/v1/agent/profiles?limit=1000')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_validation_and_malformed_json_return_contract_errors(): void
    {
        $this->postJson('/api/v1/agent/heartbeat', [])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->call('POST', '/api/v1/agent/heartbeat', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-agent-key'], '{')->assertBadRequest()->assertJsonPath('error.code', 'INVALID_JSON');
        $this->postJson('/api/v1/agent/commands/claim', ['limit' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/agent/heartbeat', ['padding' => str_repeat('x', 1048577)])->assertStatus(413);
    }

    public function test_claim_replay_is_stable_and_no_second_worker_receives_same_job(): void
    {
        $this->command();
        $this->heartbeat();
        $key = (string) Str::uuid();
        $first = $this->postIdempotent('commands/claim', ['limit' => 1], $key)->assertOk()->json('data');
        $this->postIdempotent('commands/claim', ['limit' => 1], $key)->assertJsonPath('data', $first);
        $this->postIdempotent('commands/claim', ['limit' => 2], $key)->assertConflict();
        $this->postIdempotent('commands/claim', ['limit' => 1])->assertJsonPath('data', []);
        $this->assertStringNotContainsString($first[0]['lease_token'], DB::table('agent_commands')->value('lease_token'));
        $this->assertStringNotContainsString($first[0]['lease_token'], DB::table('agent_idempotency')->value('response'));
    }

    public function test_progress_lease_and_result_replay_after_expiry(): void
    {
        $command = $this->command();
        $claimed = $this->claim()[0];
        $lease = $this->leaseBody($claimed);
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/lease', $lease)->assertOk()->assertJsonPath('data.cancel_requested', false);
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/progress', $lease + ['progress_seq' => 2, 'progress_percent' => 50, 'stage' => 'sending'])->assertOk();
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/progress', $lease + ['progress_seq' => 1, 'progress_percent' => 10, 'stage' => 'old'])->assertOk();
        $this->assertEquals(50, $command->fresh()->progress_percent);
        $body = $lease + ['execution_key' => $claimed['execution_key'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'output' => ['provider_message_id' => 'msg-123'], 'error' => null];
        $key = (string) Str::uuid();
        $path = 'commands/'.$command->id.'/result';
        $this->postIdempotent($path, $body, $key)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->travel(3)->minutes();
        $this->postIdempotent($path, $body, $key)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->postIdempotent($path, $body)->assertConflict();
        $this->assertEquals('succeeded', $command->fresh()->status);
    }

    public function test_missing_heartbeat_does_not_expire_command_before_twelve_hours(): void
    {
        $command = $this->command();
        $claimed = $this->claim()[0];
        $this->travel(121)->seconds();
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/lease', $this->leaseBody($claimed))->assertOk()->assertJsonPath('data.lease_expires_at', $claimed['lease_expires_at']);
        $this->heartbeat();
        $this->postIdempotent('commands/claim', ['limit' => 1])->assertJsonPath('data', []);
        $this->assertEquals('leased', $command->fresh()->status);
    }

    public function test_capabilities_and_stale_assignment_filter_claims(): void
    {
        $command = $this->command(type: 'group.leave');
        $this->assertSame([], $this->claim());
        $command->update(['type' => 'message.send']);
        $claimed = $this->claim()[0];
        AgentProfile::where('id', $command->profile_id)->update(['assignment_version' => 2]);
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/lease', $this->leaseBody($claimed))->assertConflict()->assertJsonPath('error.code', 'STALE_ASSIGNMENT');
    }

    public function test_claim_keeps_delivering_same_profile_jobs_despite_running_unknown_and_full_capacity(): void
    {
        $profile = $this->profile();
        $first = $this->command($profile);
        $running = $this->claim()[0];
        $first->update(['status' => 'running']);
        $unknown = $this->command($profile);
        $unknown->update(['status' => 'unknown', 'dispatched_at' => now()]);
        $jobs = collect(range(1, 4))->map(fn () => $this->command($profile));
        $heartbeat = json_decode(DB::table('agent_runtime')->value('heartbeat'), true);
        $heartbeat['capacity'] = 0;
        $heartbeat['running_command_ids'] = [$running['id']];
        DB::table('agent_runtime')->update(['max_concurrency' => 1, 'heartbeat' => json_encode($heartbeat)]);
        $batch = $this->postIdempotent('commands/claim', ['limit' => 3])->assertOk()->json('data');
        $this->assertCount(3, $batch);
        $next = $this->postIdempotent('commands/claim', ['limit' => 3])->assertOk()->json('data');
        $this->assertCount(1, $next);
        $this->assertEqualsCanonicalizing($jobs->pluck('id')->all(), array_column(array_merge($batch, $next), 'id'));
        $this->assertSame([], $this->postIdempotent('commands/claim', ['limit' => 3])->assertOk()->json('data'));
    }

    public function test_long_running_and_legacy_unknown_results_are_accepted_until_twelve_hours(): void
    {
        $command = $this->command();
        $claimed = $this->claim()[0];
        $command->update(['status' => 'unknown', 'lease_expires_at' => now()->addSeconds(120)]);
        $this->travel(43199)->seconds();
        $body = $this->leaseBody($claimed) + ['execution_key' => $claimed['execution_key'], 'status' => 'succeeded', 'started_at' => now()->subHours(11)->toISOString(), 'finished_at' => now()->toISOString(), 'output' => ['provider_message_id' => 'long-running'], 'error' => null];
        $this->postIdempotent('commands/'.$command->id.'/result', $body)->assertOk();
        $this->travel(2)->seconds();
        $this->heartbeat();
        $this->assertSame('succeeded', $command->fresh()->status);
    }

    public function test_timeout_is_fixed_at_twelve_hours_and_terminal_results_cannot_be_overwritten(): void
    {
        $command = $this->command();
        $claimed = $this->claim()[0];
        $this->travel(43199)->seconds();
        $lease = $this->leaseBody($claimed);
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/lease', $lease)->assertOk()->assertJsonPath('data.lease_expires_at', $claimed['lease_expires_at']);
        $this->postJson('/api/v1/agent/commands/'.$command->id.'/progress', $lease + ['progress_seq' => 1, 'progress_percent' => 90, 'stage' => 'sending'])->assertOk();
        $this->heartbeat();
        $this->assertSame('running', $command->fresh()->status);
        $this->travel(1)->seconds();
        $this->heartbeat();
        $this->assertSame('failed', $command->fresh()->status);
        $this->assertSame('COMMAND_TIMEOUT', $command->fresh()->result['error']['code']);
        $this->assertNotNull($command->fresh()->finished_at);
        $body = $lease + ['execution_key' => $claimed['execution_key'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'output' => ['provider_message_id' => 'too-late'], 'error' => null];
        $this->postIdempotent('commands/'.$command->id.'/result', $body)->assertConflict();
        $event = $this->event(AgentProfile::find($command->profile_id), 1, (string) Str::uuid(), 'connected');
        $event['type'] = 'command.late_result';
        $event['command_id'] = $command->id;
        $event['payload'] = $body;
        $this->postJson('/api/v1/agent/events', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame('failed', $command->fresh()->status);
    }

    public function test_scheduler_expires_unknown_commands_without_app_and_preserves_future_schedules(): void
    {
        $command = $this->command();
        $claimed = $this->claim()[0];
        $command->update(['status' => 'unknown']);
        $future = $this->command(AgentProfile::find($command->profile_id));
        $future->update(['available_at' => now()->addDays(2)]);
        $this->travel(12)->hours();
        app(\App\Services\PlatformEngine::class)->tick();
        $this->assertSame('failed', $command->fresh()->status);
        $this->assertSame('queued', $future->fresh()->status);
        $this->assertSame(1, $command->fresh()->attempt);
    }

    public function test_snapshot_stages_until_complete_and_rejects_incomplete_total(): void
    {
        $profile = $this->profile();
        $sync = $this->postIdempotent('profiles/'.$profile->id.'/snapshots', ['assignment_version' => 1, 'kind' => 'contacts', 'mode' => 'full'])->assertCreated()->json('data.sync_id');
        $item = ['zalo_user_id' => '123', 'display_name' => 'Friend', 'phone' => null, 'avatar_url' => null];
        $path = '/api/v1/agent/profiles/'.$profile->id.'/snapshots/'.$sync.'/pages/1';
        $this->putJson($path, ['items' => [$item]])->assertOk();
        $this->putJson($path, ['items' => [$item]])->assertOk();
        $this->putJson($path, ['items' => [array_merge($item, ['display_name' => 'Changed'])]])->assertConflict();
        $this->assertDatabaseCount('agent_records', 0);
        $this->postIdempotent('profiles/'.$profile->id.'/snapshots/'.$sync.'/complete', ['total_pages' => 2, 'total_items' => 1])->assertConflict();
        $key = (string) Str::uuid();
        $this->postIdempotent('profiles/'.$profile->id.'/snapshots/'.$sync.'/complete', ['total_pages' => 1, 'total_items' => 1], $key)->assertOk()->assertJsonPath('data.count', 1);
        $this->postIdempotent('profiles/'.$profile->id.'/snapshots/'.$sync.'/complete', ['total_pages' => 1, 'total_items' => 1], $key)->assertOk();
        $this->assertDatabaseCount('agent_records', 1);
    }

    public function test_snapshot_rejects_duplicate_ids_across_pages_and_expiry_preserves_data(): void
    {
        $profile = $this->profile();
        $sync = $this->postIdempotent('profiles/'.$profile->id.'/snapshots', ['assignment_version' => 1, 'kind' => 'groups', 'mode' => 'full'])->json('data.sync_id');
        $item = ['group_id' => '1', 'name' => 'Group', 'member_count' => 2];
        foreach ([1, 2] as $page) {
            $this->putJson('/api/v1/agent/profiles/'.$profile->id.'/snapshots/'.$sync.'/pages/'.$page, ['items' => [$item]])->assertOk();
        }
        $this->postIdempotent('profiles/'.$profile->id.'/snapshots/'.$sync.'/complete', ['total_pages' => 2, 'total_items' => 2])->assertConflict();
        $this->travel(61)->minutes();
        $this->postIdempotent('profiles/'.$profile->id.'/snapshots/'.$sync.'/complete', ['total_pages' => 2, 'total_items' => 2])->assertConflict();
        $this->assertDatabaseCount('agent_records', 0);
    }

    private function event(AgentProfile $profile, int $sequence, string $stream, string $status): array
    {
        return ['event_id' => (string) Str::uuid(), 'profile_id' => $profile->id, 'assignment_version' => 1, 'stream_id' => $stream, 'sequence' => $sequence, 'type' => 'profile.status.changed', 'occurred_at' => now()->toISOString(), 'command_id' => null, 'payload' => ['status' => $status]];
    }

    public function test_events_are_applied_in_order_and_duplicate_conflicts_are_per_item(): void
    {
        $profile = $this->profile();
        $stream = (string) Str::uuid();
        $second = $this->event($profile, 2, $stream, 'connected');
        $first = $this->event($profile, 1, $stream, 'connecting');
        $this->postJson('/api/v1/agent/events', ['events' => [$second]])->assertOk()->assertJsonPath('data.results.0.expected_sequence', 1);
        $this->assertEquals('disconnected', $profile->fresh()->status);
        $this->postJson('/api/v1/agent/events', ['events' => [$first, $second, ['invalid' => true]]])->assertOk()->assertJsonPath('data.results.1.status', 'duplicate')->assertJsonPath('data.results.2.status', 'rejected');
        $this->assertEquals('connected', $profile->fresh()->status);
        $second['payload']['status'] = 'error';
        $this->postJson('/api/v1/agent/events', ['events' => [$second]])->assertJsonPath('data.results.0.error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_qr_is_encrypted_and_removed_from_event_history(): void
    {
        $profile = $this->profile();
        $command = $this->command($profile, 'profile.login_qr');
        $this->claim();
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=';
        $event = $this->event($profile, 1, (string) Str::uuid(), 'awaiting_qr');
        $event['type'] = 'profile.qr.updated';
        $event['command_id'] = $command->id;
        $event['payload'] = ['qr_id' => (string) Str::uuid(), 'image_base64' => $png, 'mime_type' => 'image/png', 'expires_at' => now()->addMinute()->toISOString()];
        $this->postJson('/api/v1/agent/events', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertEquals($png, $profile->fresh()->qr['image_base64']);
        $this->assertStringNotContainsString($png, DB::table('agent_profiles')->value('qr'));
        $history = json_decode(\Illuminate\Support\Facades\Crypt::decryptString(DB::table('agent_events')->value('body')), true);
        $this->assertArrayNotHasKey('image_base64', $history['payload']);
        $this->travel(61)->seconds();
        $this->heartbeat();
        $this->assertNull($profile->fresh()->qr);
    }

    public function test_attachment_requires_live_lease_and_matching_reference(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('agent/test.txt', 'hello');
        $command = $this->command();
        $id = (string) Str::uuid();
        $command->update(['payload' => ['content' => ['attachment_id' => $id]]]);
        DB::table('agent_attachments')->insert(['id' => $id, 'command_id' => $command->id, 'disk' => 'local', 'path' => 'agent/test.txt', 'mime_type' => 'text/plain', 'sha256' => hash('sha256', 'hello'), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/agent/attachments/'.$id.'/download')->assertConflict();
        $this->claim();
        $this->get('/api/v1/agent/attachments/'.$id.'/download')->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', 'hello'));
    }

    public function test_invalid_event_is_isolated_and_late_result_within_twelve_hours_is_applied(): void
    {
        $profile = $this->profile();
        $command = $this->command($profile);
        $claimed = $this->claim()[0];
        $stream = (string) Str::uuid();
        $bad = $this->event($profile, 1, $stream, 'connecting');
        $bad['payload']['display_name'] = null;
        $this->postJson('/api/v1/agent/events', ['events' => [$bad]])->assertOk()->assertJsonPath('data.results.0.status', 'rejected');
        $this->assertDatabaseCount('agent_events', 0);
        $late = $this->event($profile, 1, $stream, 'connected');
        $late['type'] = 'command.late_result';
        $late['command_id'] = $command->id;
        $late['payload'] = $this->leaseBody($claimed) + ['execution_key' => $claimed['execution_key'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'output' => ['provider_message_id' => '123'], 'error' => null];
        $this->travel(121)->seconds();
        $this->postJson('/api/v1/agent/events', ['events' => [$late]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertEquals('succeeded', $command->fresh()->status);
    }

    public function test_expired_qr_metadata_can_fill_a_sequence_gap(): void
    {
        $profile = $this->profile();
        $command = $this->command($profile, 'profile.login_qr');
        $this->claim();
        $stream = (string) Str::uuid();
        $second = $this->event($profile, 2, $stream, 'connected');
        $this->postJson('/api/v1/agent/events', ['events' => [$second]])->assertOk();
        $first = $this->event($profile, 1, $stream, 'awaiting_qr');
        $first['type'] = 'profile.qr.updated';
        $first['command_id'] = $command->id;
        $first['payload'] = ['qr_id' => (string) Str::uuid(), 'mime_type' => 'image/png', 'expires_at' => now()->subMinute()->toISOString(), 'image_removed' => true];
        $this->postJson('/api/v1/agent/events', ['events' => [$first]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertEquals('connected', $profile->fresh()->status);
        $this->assertNull($profile->fresh()->qr);
    }

    public function test_console_can_create_profile_and_queue_login_without_node_registration(): void
    {
        $user = User::factory()->create();
        $this->artisan('agent:profile', ['email' => $user->email, 'name' => 'Agent test'])->assertExitCode(0);
        $profile = AgentProfile::firstOrFail();
        $this->artisan('agent:login', ['profile' => $profile->id])->assertExitCode(0);
        $this->artisan('agent:login', ['profile' => $profile->id])->assertExitCode(0);
        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertEquals('profile.login_qr', $this->claim()[0]['type']);
    }
}
