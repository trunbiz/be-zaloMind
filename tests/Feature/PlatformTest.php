<?php

namespace Tests\Feature;

use App\Models\AffiliateCommission;
use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceItem;
use App\Services\Navigation;
use App\Services\Platform;
use App\Services\PlatformEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        $role = $admin ? 'admin' : 'user';
        Role::findOrCreate($role, 'web');

        return User::factory()->create()->assignRole($role);
    }

    private function profile(User $user): AgentProfile
    {
        return AgentProfile::create(['user_id' => $user->id, 'display_name' => 'CSKH', 'status' => 'connected']);
    }

    private function action(User $user, string $section, string $action, array $data = [], bool $admin = false, ?string $key = null)
    {
        return $this->actingAs($user)->postJson(($admin ? '/admin/' : '/workspace/').$section.'/'.$action, $data + ['request_key' => $key ?? (string) Str::uuid()]);
    }

    private function plan(): Plan
    {
        return Plan::create(['code' => 'pro', 'name' => 'Pro', 'monthly_price_vnd' => 299000, 'yearly_price_vnd' => 2990000, 'profiles_max' => 5, 'messages_per_day' => 1000]);
    }

    public function test_user_creates_profile_and_requests_qr_from_web_with_idempotency(): void
    {
        $user = $this->user();
        $key = (string) Str::uuid();
        $profile = $this->action($user, 'profiles', 'create', ['display_name' => 'CSKH'], key: $key)->assertOk()->json('data.record');
        $this->action($user, 'profiles', 'create', ['display_name' => 'CSKH'], key: $key)->assertOk();
        $this->assertDatabaseCount('agent_profiles', 1);
        $this->action($user, 'profiles', 'login', ['id' => $profile['id'], 'expected_version' => 1])->assertOk();
        $this->assertDatabaseHas('agent_commands', ['profile_id' => $profile['id'], 'type' => 'profile.login_qr']);
        $this->action($user, 'profiles', 'create', ['display_name' => 'Too many'])->assertUnprocessable();
    }

    public function test_owner_checks_prevent_cross_account_operations_and_qr_leak(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $profile = $this->profile($owner);
        $this->action($other, 'profiles', 'logout', ['id' => $profile->id, 'expected_version' => 1])->assertNotFound();
        $this->actingAs($other)->getJson('/workspace/profiles/live')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->postJson('/admin/users/create', ['request_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_campaign_reserves_quota_is_scheduled_and_cancels_atomically(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $data = ['profile_id' => $profile->id, 'name' => 'Test', 'text' => 'Hello', 'recipient_ids' => "123\n456", 'interval_seconds' => 5, 'scheduled_at' => now()->addHour()->toISOString()];
        $campaign = $this->action($user, 'message-friends', 'create', $data)->assertOk()->json('data.record');
        $this->assertDatabaseCount('agent_commands', 2);
        $this->assertEquals(2, app(Platform::class)->used($user));
        $this->assertTrue(AgentCommand::first()->available_at->gt(now()));
        $this->action($user, 'message-friends', 'pause', ['id' => $campaign['id'], 'expected_version' => 1])->assertOk();
        $this->assertFalse(app(PlatformEngine::class)->canClaim(AgentCommand::first(), $profile));
        $this->action($user, 'message-friends', 'cancel', ['id' => $campaign['id'], 'expected_version' => 2])->assertOk();
        $this->assertEquals(0, app(Platform::class)->used($user));
    }

    public function test_failed_bulk_campaign_rolls_back_all_messages(): void
    {
        Plan::where('code', 'free')->update(['messages_per_day' => 1]);
        $user = $this->user();
        $profile = $this->profile($user);
        $this->action($user, 'message-friends', 'create', ['profile_id' => $profile->id, 'name' => 'Too big', 'text' => 'Hello', 'recipient_ids' => "123\n456", 'interval_seconds' => 5])->assertUnprocessable();
        $this->assertDatabaseCount('agent_commands', 0);
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_recipient_picker_sends_only_selected_friends_or_groups(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        foreach (['message-friends' => 'contacts', 'message-groups' => 'groups'] as $section => $kind) {
            $ids = [];
            foreach (['00123', '00456', '00789'] as $providerId) {
                $ids[] = (string) DB::table('agent_records')->insertGetId(['profile_id' => $profile->id, 'kind' => $kind, 'provider_id' => $providerId, 'data' => json_encode([$kind === 'groups' ? 'group_id' : 'zalo_user_id' => $providerId, $kind === 'groups' ? 'name' : 'display_name' => 'Người nhận '.$providerId]), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->actingAs($user)->get('/workspace/'.$section)->assertOk()->assertSee('data-recipient-picker', false)->assertSee('Người nhận 00123')->assertDontSee('ID người nhận / nhóm');
            foreach ([[$ids[0]], [$ids[0], $ids[1]]] as $selected) {
                $campaign = $this->action($user, $section, 'create', ['profile_id' => $profile->id, 'name' => 'Selected', 'text' => 'Hello', 'interval_seconds' => 5, 'recipient_selection' => 'selected', 'selected_recipients' => $selected])->assertOk()->json('data.record');
                $commands = AgentCommand::where('campaign_id', $campaign['id'])->get();
                $this->assertCount(count($selected), $commands);
                $this->assertEquals(array_slice(['00123', '00456'], 0, count($selected)), $commands->map(fn ($command) => $command->payload['recipient']['id'])->all());
                $this->assertSame($kind === 'groups' ? 'group' : 'user', $commands->first()->payload['recipient']['type']);
            }
        }
    }

    public function test_recipient_picker_rejects_empty_stale_wrong_kind_and_other_profile_selections(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $otherProfile = $this->profile($this->user());
        $id = (string) DB::table('agent_records')->insertGetId(['profile_id' => $otherProfile->id, 'kind' => 'contacts', 'provider_id' => 'private-id', 'data' => json_encode(['zalo_user_id' => 'private-id', 'display_name' => 'Private friend']), 'created_at' => now(), 'updated_at' => now()]);
        $group = (string) DB::table('agent_records')->insertGetId(['profile_id' => $profile->id, 'kind' => 'groups', 'provider_id' => 'group', 'data' => json_encode(['group_id' => 'group']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get('/workspace/message-friends')->assertOk()->assertDontSee('Private friend');
        foreach ([[], [$id], [$group], ['999999']] as $selected) {
            $this->action($user, 'message-friends', 'create', ['profile_id' => $profile->id, 'name' => 'Invalid', 'text' => 'Hello', 'interval_seconds' => 5, 'recipient_selection' => 'selected', 'selected_recipients' => $selected, 'recipient_ids' => 'manual-fallback'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseCount('agent_commands', 0);
    }

    public function test_opt_out_and_moderation_are_enforced_before_execution(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $this->action($user, 'opt-outs', 'create', ['recipient' => '123'])->assertOk();
        $this->action($user, 'message-friends', 'create', ['profile_id' => $profile->id, 'name' => 'Blocked', 'text' => 'Hello', 'recipient_ids' => '123', 'interval_seconds' => 5])->assertUnprocessable();
        WorkspaceItem::create(['kind' => 'settings', 'title' => 'system', 'data' => ['moderation_required' => true]]);
        $this->action($user, 'inbox', 'reply', ['profile_id' => $profile->id, 'conversation_id' => '456', 'conversation_type' => 'user', 'text' => 'Hello'])->assertOk();
        $command = AgentCommand::firstOrFail();
        $this->assertEquals('pending_approval', $command->status);
        $admin = $this->user(true);
        $this->action($admin, 'moderation', 'approve', ['id' => $command->id, 'expected_version' => 1], true)->assertOk();
        $this->assertEquals('queued', $command->fresh()->status);
        $this->action($user, 'opt-outs', 'create', ['recipient' => '456'])->assertOk();
        $this->assertFalse(app(PlatformEngine::class)->canClaim($command->fresh(), $profile));
        $this->assertEquals('cancelled', $command->fresh()->status);
    }

    public function test_order_payment_is_idempotent_and_grants_subscription_once(): void
    {
        $user = $this->user();
        $admin = $this->user(true);
        $plan = $this->plan();
        $order = $this->action($user, 'billing', 'create', ['plan_id' => $plan->id, 'billing_period' => 'monthly'])->assertOk()->json('data.record');
        $key = (string) Str::uuid();
        $body = ['id' => $order['id'], 'expected_version' => 1, 'reference' => 'BANK-001', 'amount_vnd' => 299000, 'reason' => 'Đã đối chiếu'];
        $this->action($admin, 'payments', 'confirm', $body, true, $key)->assertOk();
        $this->action($admin, 'payments', 'confirm', $body, true, $key)->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertEquals(5, app(Platform::class)->limits($user)['profiles_max']);
        $this->action($admin, 'payments', 'confirm', $body, true)->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_payment_wrong_amount_does_not_grant_access(): void
    {
        $user = $this->user();
        $admin = $this->user(true);
        $plan = $this->plan();
        $order = app(Platform::class)->createOrder($user, $plan, 'monthly');
        $this->action($admin, 'payments', 'confirm', ['id' => $order->id, 'expected_version' => 1, 'reference' => 'WRONG', 'amount_vnd' => 1], true)->assertUnprocessable();
        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_affiliate_is_attributed_and_paid_only_after_approval(): void
    {
        $referrer = $this->user();
        $user = $this->user();
        $user->referred_by = $referrer->id;
        $user->save();
        $admin = $this->user(true);
        WorkspaceItem::create(['kind' => 'settings', 'title' => 'system', 'data' => ['affiliate_percent' => 10]]);
        $order = app(Platform::class)->createOrder($user, $this->plan(), 'monthly');
        $this->action($admin, 'payments', 'confirm', ['id' => $order->id, 'expected_version' => 1, 'reference' => 'AFF-1', 'amount_vnd' => 299000], true)->assertOk();
        $commission = AffiliateCommission::firstOrFail();
        $this->assertEquals(29900, $commission->amount_vnd);
        $this->action($referrer, 'affiliate', 'request', ['id' => $commission->id, 'expected_version' => 1])->assertUnprocessable();
        $this->action($admin, 'affiliate', 'approve', ['id' => $commission->id, 'expected_version' => 1], true)->assertOk();
        $this->action($referrer, 'affiliate', 'request', ['id' => $commission->id, 'expected_version' => 2])->assertOk();
        $this->action($admin, 'affiliate', 'paid', ['id' => $commission->id, 'expected_version' => 3, 'reference' => 'PAYOUT-1'], true)->assertOk();
        $this->assertEquals('paid', $commission->fresh()->status);
    }

    public function test_admin_suspension_revokes_tokens_and_cancels_jobs(): void
    {
        $user = $this->user();
        $admin = $this->user(true);
        $profile = $this->profile($user);
        $user->createToken('test');
        $command = app(Platform::class)->queue($user, $profile, 'contacts.sync', ['mode' => 'full']);
        $this->action($admin, 'users', 'suspend', ['id' => $user->id, 'expected_version' => 1, 'reason' => 'Khóa theo yêu cầu'], true)->assertOk();
        $this->assertEquals('cancelled', $command->fresh()->status);
        $this->assertEquals(0, $user->tokens()->count());
        $this->actingAs($user->fresh())->get('/dashboard')->assertForbidden();
        $this->action($admin, 'users', 'suspend', ['id' => $admin->id, 'expected_version' => 1], true)->assertUnprocessable();
    }

    public function test_contact_import_is_atomic_and_proxy_password_is_encrypted(): void
    {
        $user = $this->user();
        $this->action($user, 'contact-lists', 'import', ['list_name' => 'Khách', 'csv_text' => "name,phone,zalo_user_id,labels,birthday\nAn,+84901234567,123,vip,1990-10-21"])->assertOk();
        $this->assertEquals('vip', WorkspaceItem::where('kind', 'contact')->first()->data['labels']);
        $admin = $this->user(true);
        $this->action($admin, 'proxies', 'create', ['title' => 'Proxy', 'scheme' => 'http', 'host' => '127.0.0.1', 'port' => 8888, 'username' => 'test', 'password' => 'proxy-secret'], true)->assertOk()->assertJsonMissingPath('data.record.data.password');
        $raw = DB::table('workspace_items')->where('kind', 'proxy')->value('data');
        $this->assertStringNotContainsString('proxy-secret', $raw);
    }

    public function test_scheduler_creates_drip_once_and_rotation_repeats_later(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $body = ['name' => 'Drip', 'profile_id' => $profile->id, 'source' => 'message-friends', 'recipient_ids' => '123', 'steps' => "0|Hello\n60|Follow up", 'interval_minutes' => 1440];
        $this->action($user, 'drip', 'create', $body)->assertOk();
        $this->artisan('platform:tick')->assertExitCode(0);
        $this->artisan('platform:tick')->assertExitCode(0);
        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('agent_commands', 2);
        $this->assertEquals('completed', WorkspaceItem::where('kind', 'automation')->first()->status);
        $this->assertEquals(60, AgentCommand::orderBy('available_at')->first()->available_at->diffInMinutes(AgentCommand::orderByDesc('available_at')->first()->available_at));
    }

    public function test_phone_resolution_followup_creates_one_message_without_double_quota(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $this->action($user, 'message-phones', 'create', ['name' => 'Phone', 'profile_id' => $profile->id, 'text' => 'Hello', 'recipient_ids' => '+84901234567', 'interval_seconds' => 5])->assertOk();
        $parent = AgentCommand::firstOrFail();
        $this->assertEquals('contact.resolve', $parent->type);
        $parent->update(['status' => 'succeeded', 'result' => ['output' => ['zalo_user_id' => '123']]]);
        app(PlatformEngine::class)->completed($parent);
        app(PlatformEngine::class)->completed($parent->fresh());
        $this->assertEquals(1, AgentCommand::where('parent_command_id', $parent->id)->count());
        $this->assertEquals(1, app(Platform::class)->used($user));
    }

    public function test_populated_pages_render_and_have_no_phase_placeholders(): void
    {
        $admin = $this->user(true);
        $profile = $this->profile($admin);
        app(Platform::class)->queue($admin, $profile, 'message.send', ['recipient' => ['type' => 'user', 'id' => '123'], 'content' => ['type' => 'text', 'text' => 'Hello']]);
        $plan = $this->plan();
        app(Platform::class)->createOrder($admin, $plan, 'monthly');
        WorkspaceItem::create(['user_id' => $admin->id, 'kind' => 'contact', 'title' => 'Khách', 'data' => ['phone' => '+84901234567', 'zalo_user_id' => '123', 'list_name' => 'VIP', 'labels' => 'vip', 'birthday' => '1990-10-21']]);
        $this->actingAs($admin);
        foreach (['user', 'admin'] as $area) {
            foreach (app(Navigation::class)->pages($area, $admin) as $page) {
                $this->get($page['url'])->assertOk()->assertDontSee('theo lộ trình dự án')->assertDontSee('sẽ được triển khai ở giai đoạn');
            }
        }
        $this->get('/workspace/reports/export')->assertOk()->assertDownload();
    }

    public function test_api_login_permissions_and_messages_contract(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'test'])->assertOk()->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/messages', ['profile_id' => $profile->id, 'recipient' => ['type' => 'user', 'id' => '123'], 'content' => ['type' => 'text', 'text' => 'Hello']], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(202);
        $this->getJson('/api/v1/profiles/'.$profile->id.'/login-qr')->assertNotFound();
        $otherProfile = $this->profile($this->user());
        $this->getJson('/api/v1/profiles/'.$otherProfile->id)->assertNotFound();
    }

    public function test_web_profile_qr_and_campaign_complete_through_agent_protocol(): void
    {
        config(['zalomind.agent_api_key' => 'integration-key']);
        $user = $this->user();
        $profile = $this->action($user, 'profiles', 'create', ['display_name' => 'Integration'])->json('data.record');
        $this->action($user, 'profiles', 'login', ['id' => $profile['id'], 'expected_version' => 1])->assertOk();
        $this->withToken('integration-key')->postJson('/api/v1/agent/heartbeat', ['app_version' => 'test', 'protocol_version' => '1', 'capabilities' => ['profile.login_qr', 'message.send'], 'capacity' => 2, 'running_command_ids' => [], 'health' => ['status' => 'healthy', 'queue_depth' => 0]])->assertOk();
        $claim = fn () => $this->postJson('/api/v1/agent/commands/claim', ['limit' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.0');
        $login = $claim();
        $this->assertEquals('profile.login_qr', $login['type']);
        $qr = ['qr_id' => (string) Str::uuid(), 'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=', 'mime_type' => 'image/png', 'expires_at' => now()->addMinute()->toISOString()];
        $this->postJson('/api/v1/agent/events', ['events' => [['event_id' => (string) Str::uuid(), 'profile_id' => $profile['id'], 'assignment_version' => 1, 'stream_id' => (string) Str::uuid(), 'sequence' => 1, 'type' => 'profile.qr.updated', 'occurred_at' => now()->toISOString(), 'command_id' => $login['id'], 'payload' => $qr]]])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->getJson('/workspace/profiles/live')->assertOk()->assertJsonPath('data.0.qr.qr_id', $qr['qr_id']);
        $result = function (array $command, array $output) {
            $body = array_intersect_key($command, array_flip(['lease_token', 'attempt', 'assignment_version', 'execution_key'])) + ['status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'output' => $output, 'error' => null];
            $this->postJson('/api/v1/agent/commands/'.$command['id'].'/result', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        };
        $result($login, ['zalo_user_id' => 'owner-zalo', 'display_name' => 'Integration']);
        $this->action($user, 'message-friends', 'create', ['profile_id' => $profile['id'], 'name' => 'End to end', 'text' => 'Hello', 'recipient_ids' => 'recipient-zalo', 'interval_seconds' => 5])->assertOk();
        $send = $claim();
        $this->assertEquals('message.send', $send['type']);
        $result($send, ['provider_message_id' => 'provider-message-123']);
        $this->get('/workspace/reports')->assertOk()->assertSee('Thành công')->assertSee('provider-message-123');
        $this->assertNull(AgentProfile::find($profile['id'])->qr);
    }

    public function test_referral_link_is_saved_during_registration(): void
    {
        $referrer = $this->user();
        $this->get('/register?ref='.$referrer->referral_code)->assertOk();
        $this->post('/register', ['name' => 'New customer', 'email' => 'referred@example.test', 'password' => 'example-password-123', 'password_confirmation' => 'example-password-123'])->assertRedirect('/dashboard');
        $this->assertEquals($referrer->id, User::where('email', 'referred@example.test')->firstOrFail()->referred_by);
    }

    public function test_phone_opt_out_also_blocks_the_known_zalo_id(): void
    {
        $user = $this->user();
        $profile = $this->profile($user);
        WorkspaceItem::create(['user_id' => $user->id, 'kind' => 'contact', 'title' => 'Khách', 'data' => ['phone' => '+84901234567', 'zalo_user_id' => 'zalo-person', 'list_name' => 'VIP']]);
        $this->action($user, 'opt-outs', 'create', ['recipient' => '0901234567'])->assertOk();
        $this->action($user, 'inbox', 'reply', ['profile_id' => $profile->id, 'conversation_id' => 'zalo-person', 'conversation_type' => 'user', 'text' => 'Hello'])->assertUnprocessable();
        $this->assertDatabaseCount('agent_commands', 0);
    }
}
