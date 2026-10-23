<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentEvents;
use App\Services\Platform;
use App\Services\PlatformEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_stored_and_duplicate_does_not_create_another_record(): void
    {
        $owner = User::factory()->create();
        $profile = AgentProfile::create(['user_id' => $owner->id, 'display_name' => 'Fixture', 'status' => 'connected', 'zalo_user_id' => 'self']);
        $event = ['event_id' => (string) Str::uuid(), 'profile_id' => $profile->id,
            'assignment_version' => $profile->fresh()->assignment_version, 'stream_id' => (string) Str::uuid(),
            'sequence' => 1, 'occurred_at' => now()->toISOString(), 'command_id' => null, 'type' => 'message.received',
            'payload' => ['message_id' => 'history-1', 'conversation' => ['type' => 'group', 'id' => 'g123'],
                'sender_id' => 'other', 'content' => ['type' => 'text', 'text' => 'Historical fixture'],
                'sent_at' => now()->subDay()->toISOString(), 'historical' => true]];
        $receiver = app(AgentEvents::class);
        $this->assertSame('accepted', $receiver->receive($event)['status']);
        $this->assertSame('duplicate', $receiver->receive($event)['status']);
        $record = DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', 'messages')->first();
        $this->assertTrue(json_decode($record->data, true)['historical']);
        $this->assertSame(1, DB::table('agent_records')->where('profile_id', $profile->id)->count());
    }

    public function test_historical_messages_return_before_automation_queries(): void
    {
        $profile = new AgentProfile(['zalo_user_id' => 'self']);
        $engine = new PlatformEngine($this->mock(Platform::class));
        DB::shouldReceive('table')->never();
        // A query for contacts/rules would reach the database if this guard failed.
        DB::shouldReceive('connection')->never();
        $engine->incoming(['type' => 'message.received', 'payload' => ['sender_id' => 'other', 'historical' => true]], $profile);
        $this->assertTrue(true);
    }
}
