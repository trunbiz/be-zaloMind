<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\WorkspaceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConversationsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        Role::findOrCreate('user', 'web');

        return User::factory()->create()->assignRole('user');
    }

    private function profile(User $owner): AgentProfile
    {
        return AgentProfile::create(['user_id' => $owner->id, 'display_name' => 'CSKH', 'status' => 'connected', 'zalo_user_id' => 'self']);
    }

    private function message(AgentProfile $profile, string $id, string $text, string $type = 'group', string $sender = 'friend', bool $historical = true, ?string $sentAt = null): array
    {
        $profile->refresh();

        return ['event_id' => (string) Str::uuid(), 'profile_id' => $profile->id, 'assignment_version' => $profile->assignment_version,
            'stream_id' => $profile->stream_id ?: (string) Str::uuid(), 'sequence' => $profile->last_sequence + 1,
            'type' => 'message.received', 'occurred_at' => now()->toISOString(), 'command_id' => null,
            'payload' => ['message_id' => $id, 'conversation' => ['type' => $type, 'id' => 'same-id'], 'sender_id' => $sender,
                'content' => ['type' => 'text', 'text' => $text], 'sent_at' => $sentAt ?? now()->toISOString(), 'historical' => $historical]];
    }

    private function push(array $events)
    {
        config(['zalomind.agent_api_key' => 'inbox-test']);

        return $this->postJson('/api/v1/agent/events', ['events' => $events], ['Authorization' => 'Bearer inbox-test', 'Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    private function peer(AgentProfile $profile, string $kind, string $name): void
    {
        DB::table('agent_records')->insert(['profile_id' => $profile->id, 'kind' => $kind, 'provider_id' => 'same-id',
            'data' => json_encode(['name' => $name, 'display_name' => $name]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_inbox_shows_named_personal_and_group_conversations_and_chronological_sent_and_received_messages(): void
    {
        $owner = $this->owner();
        $profile = $this->profile($owner);
        $this->peer($profile, 'groups', 'Nhóm hỗ trợ');
        $this->peer($profile, 'contacts', 'Bạn An');
        $this->push([$this->message($profile, 'newer', 'Tin mình gửi', sender: 'self', sentAt: now()->toISOString())]);
        $this->push([$this->message($profile, 'older', '<script>alert(1)</script>', sentAt: now()->subDay()->toISOString())]);
        $this->push([$this->message($profile, 'private', 'Hội thoại cá nhân', type: 'user')]);
        $url = '/workspace/inbox?profile_id='.$profile->id.'&conversation_type=group&conversation_id=same-id';
        $response = $this->actingAs($owner)->get($url)->assertOk()->assertSee('Nhóm hỗ trợ')->assertSee('Bạn An')
            ->assertSee('Tin mình gửi')->assertSee('Bạn')->assertSee('Lịch sử đồng bộ')->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSeeInOrder(['&lt;script&gt;alert(1)&lt;/script&gt;', 'Tin mình gửi'], false);
        $this->assertSame(2, $response->viewData('screen')['inbox']['conversations']->total());
        $this->assertCount(2, $response->viewData('screen')['inbox']['messages']);
        $this->actingAs($owner)->get('/workspace/inbox?q='.urlencode('Bạn An').'&type=user')->assertOk()->assertSee('Bạn An')->assertDontSee('Nhóm hỗ trợ');
        $this->actingAs($owner)->get($url.'&fragment=1')->assertOk()->assertSee('data-inbox-panels', false)->assertDontSee('id="chat-reply"', false);
    }

    public function test_duplicate_message_with_new_event_id_consumes_sequence_without_duplicate_storage_or_automation(): void
    {
        $profile = $this->profile($this->owner());
        $event = $this->message($profile, 'message-1', 'History');
        $this->push([$event])->assertJsonPath('data.results.0.status', 'accepted');
        $this->push([$event])->assertJsonPath('data.results.0.status', 'duplicate');
        $duplicate = $this->message($profile, 'message-1', 'History');
        $duplicate['payload']['historical'] = false;
        $this->push([$duplicate])->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertSame(2, $profile->fresh()->last_sequence);
        $this->assertSame(1, DB::table('agent_records')->where('kind', 'messages')->count());
        $this->push([$this->message($profile, 'message-2', 'Next')])->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame(3, $profile->fresh()->last_sequence);
        $this->assertDatabaseCount('agent_commands', 0);
    }

    public function test_history_and_own_messages_never_trigger_automation_but_new_incoming_messages_do(): void
    {
        $owner = $this->owner();
        $profile = $this->profile($owner);
        WorkspaceItem::create(['kind' => 'contact', 'user_id' => $owner->id, 'title' => 'Friend', 'data' => ['zalo_user_id' => 'friend', 'labels' => 'VIP']]);
        WorkspaceItem::create(['kind' => 'automation', 'user_id' => $owner->id, 'title' => 'Reply', 'data' => ['mode' => 'incoming', 'profile_id' => $profile->id, 'label' => 'VIP', 'interval_minutes' => 5, 'steps' => [['text' => 'Hello', 'delay_minutes' => 0]]]]);
        $this->push([$this->message($profile, 'history', 'History')]);
        $this->push([$this->message($profile, 'own', 'Outgoing', sender: 'self', historical: false)]);
        $this->assertDatabaseCount('agent_commands', 0);
        $live = $this->message($profile, 'live', 'Incoming', historical: false);
        $this->push([$live]);
        $this->push([$live]);
        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertNull(DB::table('agent_records')->where('provider_id', 'live')->value('read_at'));
        $this->assertNotNull(DB::table('agent_records')->where('provider_id', 'history')->value('read_at'));
        $this->assertNotNull(DB::table('agent_records')->where('provider_id', 'own')->value('read_at'));
    }

    public function test_conversation_scope_prevents_cross_owner_access_and_reply_uses_selected_thread(): void
    {
        $owner = $this->owner();
        $profile = $this->profile($owner);
        $otherOwner = $this->owner();
        $other = $this->profile($otherOwner);
        $this->push([$this->message($profile, 'same-message', 'Visible', historical: false)]);
        $this->push([$this->message($other, 'same-message', 'Private message')]);
        $this->actingAs($owner)->get('/workspace/inbox')->assertOk()->assertSee('Visible')->assertDontSee('Private message');
        $this->actingAs($owner)->get('/workspace/inbox?profile_id='.$other->id)->assertNotFound();
        $this->actingAs($owner)->get('/workspace/inbox?fragment=1&profile_id='.$other->id.'&conversation_type=group&conversation_id=same-id')->assertNotFound();
        $body = ['request_key' => (string) Str::uuid(), 'profile_id' => $profile->id, 'conversation_type' => 'group', 'conversation_id' => 'same-id', 'text' => 'Reply'];
        $this->actingAs($owner)->post('/workspace/inbox/reply', $body)->assertRedirect(route('workspace.section', ['section' => 'inbox', 'profile_id' => $profile->id, 'conversation_type' => 'group', 'conversation_id' => 'same-id']));
        $this->actingAs($owner)->post('/workspace/inbox/reply', $body)->assertRedirect();
        $command = AgentCommand::firstOrFail();
        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertSame($profile->id, $command->profile_id);
        $this->assertSame(['type' => 'group', 'id' => 'same-id'], $command->payload['recipient']);
        $this->actingAs($owner)->postJson('/workspace/inbox/read-conversation', $body)->assertOk();
        $this->assertNotNull(DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', 'messages')->value('read_at'));
        $body['request_key'] = (string) Str::uuid();
        $body['profile_id'] = $other->id;
        $this->actingAs($owner)->postJson('/workspace/inbox/read-conversation', $body)->assertNotFound();
    }

    public function test_chat_pagination_is_by_sent_time_and_api_filters_are_owner_scoped(): void
    {
        $owner = $this->owner();
        $profile = $this->profile($owner);
        for ($index = 0; $index < 51; $index++) {
            $this->push([$this->message($profile, 'message-'.$index, 'Message '.$index, sentAt: now()->subMinutes(51 - $index)->toISOString())]);
        }
        $params = ['profile_id' => $profile->id, 'conversation_type' => 'group', 'conversation_id' => 'same-id'];
        $response = $this->actingAs($owner)->get('/workspace/inbox?'.http_build_query($params))->assertOk();
        $messages = $response->viewData('screen')['inbox']['messages'];
        $this->assertCount(50, $messages);
        $this->assertSame('message-50', $messages->first()->provider_id);
        $older = $this->actingAs($owner)->get('/workspace/inbox?'.http_build_query($params + ['messages_page' => 2]))->assertOk();
        $this->assertSame('message-0', $older->viewData('screen')['inbox']['messages']->first()->provider_id);
        $this->actingAs($owner)->getJson('/api/v1/inbox?'.http_build_query($params))->assertOk()->assertJsonCount(50, 'data');
        $this->actingAs($this->owner())->getJson('/api/v1/inbox?'.http_build_query($params))->assertOk()->assertJsonCount(0, 'data');
    }
}
