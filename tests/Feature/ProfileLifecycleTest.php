<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WorkspaceItem;
use App\Services\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        Role::findOrCreate('user', 'web');

        return User::factory()->create()->assignRole('user');
    }

    private function profile(User $owner, string $status = 'disconnected'): AgentProfile
    {
        return AgentProfile::create(['user_id' => $owner->id, 'display_name' => 'CSKH', 'status' => $status]);
    }

    private function action(User $owner, AgentProfile $profile, string $action)
    {
        return $this->actingAs($owner)->postJson('/workspace/profiles/'.$action, ['id' => $profile->id, 'request_key' => (string) Str::uuid()]);
    }

    private function agent(): void
    {
        config(['zalomind.agent_api_key' => 'lifecycle-test-key']);
        $this->withToken('lifecycle-test-key')->postJson('/api/v1/agent/heartbeat', [
            'app_version' => 'test', 'protocol_version' => '1', 'capabilities' => ['profile.login_qr', 'profile.logout', 'message.send', 'contacts.sync'],
            'capacity' => 2, 'running_command_ids' => [], 'health' => ['status' => 'healthy', 'queue_depth' => 0],
        ])->assertOk();
    }

    private function claim(): array
    {
        return $this->postJson('/api/v1/agent/commands/claim', ['limit' => 2], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function finish(array $command, array $output): void
    {
        $this->postJson('/api/v1/agent/commands/'.$command['id'].'/result', [
            'lease_token' => $command['lease_token'], 'attempt' => $command['attempt'], 'assignment_version' => $command['assignment_version'],
            'execution_key' => $command['execution_key'], 'status' => 'succeeded', 'started_at' => now()->toISOString(),
            'finished_at' => now()->toISOString(), 'output' => $output, 'error' => null,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    public function test_repeated_login_reuses_current_command_without_version_errors(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user);
        $first = $this->action($user, $profile, 'login')->assertOk()->json('data.record.id');
        $second = $this->action($user, $profile, 'login')->assertOk()->json('data.record.id');
        $this->assertEquals($first, $second);
        $this->assertDatabaseCount('agent_commands', 1);
        $this->get('/workspace/profiles')->assertOk()->assertSee('Đang lấy mã QR')->assertDontSee('Hủy hoặc xử lý lệnh trước');
    }

    public function test_old_unknown_login_does_not_trap_reconnection_or_future_sync(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user);
        $old = AgentCommand::create(['profile_id' => $profile->id, 'type' => 'profile.login_qr', 'payload' => [], 'status' => 'unknown', 'attempt' => 1]);
        $this->action($user, $profile, 'login')->assertOk();
        $this->agent();
        $login = $this->claim()[0];
        $this->assertNotEquals($old->id, $login['id']);
        $this->finish($login, ['zalo_user_id' => 'provider-owner', 'display_name' => 'CSKH']);
        $sync = app(Platform::class)->queue($user, $profile->fresh(), 'contacts.sync', ['mode' => 'full']);
        $this->assertEquals($sync->id, $this->claim()[0]['id']);
        $this->assertEquals('unknown', $old->fresh()->status);
    }

    public function test_logout_cancels_waiting_work_and_does_not_extend_cancelled_worker_lease(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user, 'connected');
        $campaign = Campaign::create(['user_id' => $user->id, 'profile_id' => $profile->id, 'name' => 'Campaign', 'kind' => 'message-friends']);
        $job = app(Platform::class)->queue($user, $profile, 'message.send', ['recipient' => ['type' => 'user', 'id' => '123'], 'content' => ['type' => 'text', 'text' => 'Hello']]);
        $this->agent();
        $running = $this->claim()[0];
        $pending = app(Platform::class)->queue($user, $profile, 'contacts.sync', ['mode' => 'full']);
        $rule = WorkspaceItem::create(['kind' => 'automation', 'user_id' => $user->id, 'title' => 'Rule', 'data' => ['profile_id' => $profile->id, 'mode' => 'incoming']]);
        $this->action($user, $profile, 'logout')->assertOk();
        $this->assertEquals('cancelled', $pending->fresh()->status);
        $this->assertEquals('cancelled', $campaign->fresh()->status);
        $this->assertEquals('paused', $rule->fresh()->status);
        $this->assertTrue($job->fresh()->cancel_requested);
        $expires = $job->fresh()->lease_expires_at->toISOString();
        $this->postJson('/api/v1/agent/commands/'.$job->id.'/lease', ['attempt' => $running['attempt'], 'lease_token' => $running['lease_token'], 'assignment_version' => 1])->assertOk()->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.lease_expires_at', $expires);
        $logout = $this->claim()[0];
        $this->assertEquals('profile.logout', $logout['type']);
        $this->assertEquals('leased', $job->fresh()->status);
        $this->finish($logout, ['logged_out' => true]);
        $this->assertEquals('disconnected', $profile->fresh()->status);
        $this->assertEquals('leased', $job->fresh()->status);
    }

    public function test_delete_connected_profile_removes_it_immediately_but_keeps_quota_and_logout_cleanup(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user, 'connected');
        $job = app(Platform::class)->queue($user, $profile, 'message.send', ['recipient' => ['type' => 'user', 'id' => '123'], 'content' => ['type' => 'text', 'text' => 'Hello']]);
        $job->update(['status' => 'unknown', 'attempt' => 1]);
        $pending = app(Platform::class)->queue($user, $profile, 'contacts.sync', ['mode' => 'full']);
        $this->action($user, $profile, 'delete')->assertOk()->assertJsonPath('data.record.deleted', true);
        $this->assertNull(AgentProfile::find($profile->id));
        $this->assertNotNull(AgentProfile::withTrashed()->find($profile->id));
        $this->assertEquals('cancelled', $pending->fresh()->status);
        $this->assertEquals(1, app(Platform::class)->used($user));
        app(Platform::class)->createProfile($user, 'Replacement');
        $this->agent();
        $this->getJson('/api/v1/agent/profiles')->assertOk()->assertJsonFragment(['id' => $profile->id, 'deleted' => true, 'desired_state' => 'disconnected']);
        $logout = $this->claim()[0];
        $this->assertEquals('profile.logout', $logout['type']);
        $this->finish($logout, ['logged_out' => true]);
        $this->getJson('/api/v1/agent/profiles')->assertOk()->assertJsonMissing(['id' => $profile->id]);
        $this->get('/workspace/reports')->assertOk()->assertSee($job->id);
    }

    public function test_delete_never_connected_profile_cancels_qr_and_needs_no_agent(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user);
        $this->action($user, $profile, 'login')->assertOk();
        $this->action($user, $profile, 'delete')->assertOk();
        $this->assertNull(AgentProfile::find($profile->id));
        $this->assertEquals(0, AgentCommand::where('profile_id', $profile->id)->whereIn('status', ['leased', 'running'])->count());
        $this->assertEquals(0, AgentCommand::where('profile_id', $profile->id)->where('type', 'profile.login_qr')->where('status', 'queued')->count());
        $this->assertEquals(0, AgentCommand::where('profile_id', $profile->id)->where('type', 'profile.logout')->count());
    }

    public function test_old_login_result_cannot_reconnect_after_logout_requested(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user);
        $this->action($user, $profile, 'login')->assertOk();
        $this->agent();
        $login = $this->claim()[0];
        $this->action($user, $profile, 'logout')->assertOk();
        $this->finish($login, ['zalo_user_id' => 'provider-id', 'display_name' => 'CSKH']);
        $this->assertEquals('disconnecting', $profile->fresh()->status);
        $logout = $this->claim()[0];
        $this->assertEquals('profile.logout', $logout['type']);
    }

    public function test_logout_remains_available_during_maintenance(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user, 'connected');
        WorkspaceItem::create(['kind' => 'settings', 'title' => 'system', 'data' => ['maintenance_mode' => true]]);
        $this->action($user, $profile, 'logout')->assertOk();
        $this->agent();
        $this->assertEquals('profile.logout', $this->claim()[0]['type']);
    }

    public function test_other_users_cannot_cancel_or_delete_owners_profile(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $profile = $this->profile($owner, 'connected');
        $job = app(Platform::class)->queue($owner, $profile, 'contacts.sync', ['mode' => 'full']);
        $this->action($other, $profile, 'delete')->assertNotFound();
        $this->assertNotNull(AgentProfile::find($profile->id));
        $this->assertEquals('queued', $job->fresh()->status);
    }

    public function test_legacy_pending_login_is_adopted_and_expired_qr_can_be_replaced(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user);
        $legacy = AgentCommand::create(['profile_id' => $profile->id, 'type' => 'profile.login_qr', 'payload' => []]);
        $this->action($user, $profile, 'login')->assertOk()->assertJsonPath('data.record.id', $legacy->id);
        $this->assertDatabaseCount('agent_commands', 1);
        $profile->refresh()->update(['qr' => ['qr_id' => (string) Str::uuid(), 'image_base64' => 'expired', 'mime_type' => 'image/png', 'expires_at' => now()->subMinute()->toISOString()]]);
        $newId = $this->action($user, $profile, 'login')->assertOk()->json('data.record.id');
        $this->assertNotEquals($legacy->id, $newId);
        $this->assertEquals('cancelled', $legacy->fresh()->status);
        $this->assertNull($profile->fresh()->qr);
    }

    public function test_deleted_profile_does_not_reappear_after_old_connected_event(): void
    {
        $user = $this->owner();
        $profile = $this->profile($user, 'connected');
        $this->action($user, $profile, 'delete')->assertOk();
        $this->agent();
        $this->postJson('/api/v1/agent/events', ['events' => [[
            'event_id' => (string) Str::uuid(), 'profile_id' => $profile->id, 'assignment_version' => 1,
            'stream_id' => (string) Str::uuid(), 'sequence' => 1, 'type' => 'profile.status.changed',
            'occurred_at' => now()->toISOString(), 'command_id' => null, 'payload' => ['status' => 'connected'],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertNull(AgentProfile::find($profile->id));
        $this->assertEquals('disconnecting', AgentProfile::withTrashed()->find($profile->id)->status);
    }
}
