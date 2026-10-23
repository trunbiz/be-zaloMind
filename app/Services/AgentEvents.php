<?php

namespace App\Services;

use App\Http\Controllers\AgentController;
use App\Models\AgentCommand;
use App\Support\AgentApi;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AgentEvents
{
    public function cleanupQr(): void
    {
        \App\Models\AgentProfile::whereNotNull('qr')->get()->each(function ($profile) {
            if (now()->gte($profile->qr['expires_at'])) {
                $profile->update(['qr' => null]);
            }
        });
        DB::table('agent_events')->where('processed', false)->orderBy('event_id')->chunk(100, function ($events) {
            foreach ($events as $event) {
                $body = json_decode(Crypt::decryptString($event->body), true);
                if ($body['type'] === 'profile.qr.updated' && now()->gte($body['payload']['expires_at']) && isset($body['payload']['image_base64'])) {
                    unset($body['payload']['image_base64']);
                    $body['payload']['image_removed'] = true;
                    DB::table('agent_events')->where('event_id', $event->event_id)->update(['body' => Crypt::encryptString(json_encode($body))]);
                }
            }
        });
    }

    public function receive(mixed $event): array
    {
        Validator::make(['event' => $event], ['event' => 'required|array'])->validate();
        Validator::make($event, [
            'event_id' => 'required|uuid', 'profile_id' => 'required|uuid',
            'assignment_version' => 'required|integer|min:1', 'stream_id' => 'required|uuid',
            'sequence' => 'required|integer|min:1', 'occurred_at' => 'required|date',
            'command_id' => 'nullable|uuid', 'payload' => 'required|array',
            'type' => ['required', Rule::in(['profile.qr.updated', 'profile.status.changed', 'message.received', 'message.delivery.updated', 'friend.request.received', 'command.late_result'])],
        ])->validate();
        $hash = AgentApi::hash($event);
        $existing = DB::table('agent_events')->where('event_id', $event['event_id'])->first();
        if ($existing) {
            if ($existing->body_hash !== $hash) {
                AgentApi::fail('IDEMPOTENCY_CONFLICT', 'event_id đã có nội dung khác.');
            }

            return ['event_id' => $event['event_id'], 'status' => 'duplicate', 'error' => null];
        }
        $profile = app(AgentController::class)->profile($event['profile_id'], $event['assignment_version'], true);
        if ($profile->stream_id && $profile->stream_id !== $event['stream_id']) {
            AgentApi::fail('STALE_ASSIGNMENT', 'stream_id không khớp phiên hiện tại.');
        }
        if ($event['sequence'] <= $profile->last_sequence || $event['sequence'] > $profile->last_sequence + 1000 || DB::table('agent_events')->where('profile_id', $profile->id)->where('stream_id', $event['stream_id'])->where('sequence', $event['sequence'])->exists()) {
            AgentApi::fail('INVALID_STATE', 'Sequence đã dùng hoặc vượt cửa sổ 1000 sự kiện.');
        }
        $this->validatePayload($event, $profile);
        $duplicateMessage = $event['type'] === 'message.received' && DB::table('agent_records')
            ->where('profile_id', $profile->id)->where('kind', 'messages')->where('provider_id', $event['payload']['message_id'])->exists();
        if ($event['type'] === 'profile.qr.updated' && now()->gte($event['payload']['expires_at'])) {
            unset($event['payload']['image_base64']);
            $event['payload']['image_removed'] = true;
        }
        $profile->update(['stream_id' => $event['stream_id']]);
        DB::table('agent_events')->insert([
            'event_id' => $event['event_id'], 'profile_id' => $profile->id, 'stream_id' => $event['stream_id'],
            'sequence' => $event['sequence'], 'body_hash' => $hash,
            'body' => Crypt::encryptString(json_encode($event)), 'processed' => false, 'created_at' => now(),
        ]);
        // Store out-of-order events durably and apply only a contiguous stream.
        while ($next = DB::table('agent_events')->where('profile_id', $profile->id)->where('stream_id', $profile->stream_id)->where('sequence', $profile->last_sequence + 1)->where('processed', false)->first()) {
            $body = json_decode(Crypt::decryptString($next->body), true);
            $this->apply($body, $profile);
            $profile->update(['last_sequence' => $next->sequence]);
            // QR is short-lived: the profile holds its encrypted image; history keeps metadata only.
            if ($body['type'] === 'profile.qr.updated') {
                unset($body['payload']['image_base64']);
                $body['payload']['image_removed'] = true;
            }
            DB::table('agent_events')->where('event_id', $next->event_id)->update(['processed' => true, 'body' => Crypt::encryptString(json_encode($body))]);
        }
        $result = ['event_id' => $event['event_id'], 'status' => $duplicateMessage ? 'duplicate' : 'accepted', 'error' => null];
        if (DB::table('agent_events')->where('profile_id', $profile->id)->where('stream_id', $profile->stream_id)->where('processed', false)->exists()) {
            $result['expected_sequence'] = $profile->last_sequence + 1;
        }

        return $result;
    }

    private function validatePayload(array $event, $profile): void
    {
        $payload = $event['payload'];
        $rules = match ($event['type']) {
            'profile.status.changed' => ['status' => ['required', Rule::in(['disconnected', 'connecting', 'awaiting_qr', 'connected', 'expired', 'error'])], 'zalo_user_id' => 'nullable|string|max:191', 'display_name' => 'sometimes|required|string|max:255', 'avatar_url' => 'nullable|url|max:2048', 'reason' => 'nullable|string|max:2000'],
            'profile.qr.updated' => ['qr_id' => 'required|uuid', 'image_base64' => 'sometimes|nullable|string|max:349528', 'image_removed' => 'sometimes|boolean', 'mime_type' => ['required', Rule::in(['image/png'])], 'expires_at' => 'required|date'],
            'message.received' => ['message_id' => 'required|string|max:191', 'conversation.type' => ['required', Rule::in(['user', 'group'])], 'conversation.id' => 'required|string|max:191', 'sender_id' => 'required|string|max:191', 'content.type' => ['required', Rule::in(['text'])], 'content.text' => 'required|string|max:20000', 'sent_at' => 'required|date', 'historical' => 'sometimes|boolean'],
            'message.delivery.updated' => ['message_id' => 'required|string|max:191', 'status' => ['required', Rule::in(['delivered', 'read', 'failed'])], 'at' => 'required|date'],
            'friend.request.received' => ['request_id' => 'required|string|max:191', 'sender_id' => 'required|string|max:191', 'display_name' => 'required|string|max:255', 'message' => 'present|nullable|string|max:2000', 'received_at' => 'required|date'],
            'command.late_result' => ['execution_key' => 'required|uuid', 'lease_token' => 'required|string|max:200', 'attempt' => 'required|integer|min:1', 'assignment_version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['succeeded', 'failed', 'cancelled', 'unknown'])], 'started_at' => 'required|date', 'finished_at' => 'required|date|after_or_equal:started_at', 'output' => 'present|nullable|array', 'error' => 'present|nullable|array'],
        };
        Validator::make($payload, $rules)->validate();
        if ($event['type'] === 'profile.qr.updated') {
            $expired = now()->gte($payload['expires_at']);
            $image = base64_decode($payload['image_base64'] ?? '', true);
            $size = $image !== false ? @getimagesizefromstring($image) : false;
            if (! $expired && (! $size || $size[2] !== IMAGETYPE_PNG || strlen($image) > 262144)) {
                AgentApi::fail('VALIDATION_FAILED', 'QR phải là PNG hợp lệ tối đa 256 KiB.', 422);
            }
            $command = AgentCommand::find($event['command_id'] ?? '');
            if (! $command || $command->profile_id !== $profile->id || $command->assignment_version !== $profile->assignment_version || $command->type !== 'profile.login_qr' || (! $expired && (! in_array($command->status, ['leased', 'running'], true) || ! $command->lease_expires_at || $command->lease_expires_at->lte(now())))) {
                AgentApi::fail('INVALID_STATE', 'QR không thuộc lệnh login còn lease.');
            }
        }
        if ($event['type'] === 'command.late_result') {
            $command = AgentCommand::find($event['command_id'] ?? '');
            if (! $command || $command->profile_id !== $profile->id || $command->assignment_version !== $event['assignment_version'] || $payload['assignment_version'] !== $event['assignment_version'] || $command->execution_key !== $payload['execution_key'] || $command->attempt !== $payload['attempt'] || ! hash_equals((string) $command->lease_token, $payload['lease_token'])) {
                AgentApi::fail('INVALID_STATE', 'Kết quả trễ không khớp command/attempt.');
            }
        }
    }

    private function apply(array $event, $profile): void
    {
        $payload = $event['payload'];
        if ($profile->trashed() && $event['type'] !== 'command.late_result') {
            return;
        }
        if ($profile->connection_intent === 'logout' && in_array($event['type'], ['profile.status.changed', 'profile.qr.updated'], true) && ($payload['status'] ?? null) !== 'disconnected') {
            return;
        }
        if ($event['type'] === 'profile.qr.updated' && $profile->control_command_id && $event['command_id'] !== $profile->control_command_id) {
            return;
        }
        if ($event['type'] === 'profile.status.changed') {
            $changes = array_intersect_key($payload, array_flip(['status', 'zalo_user_id', 'display_name', 'avatar_url']));
            $changes['last_seen_at'] = now();
            if ($payload['status'] !== 'awaiting_qr') {
                $changes['qr'] = null;
            }
            $profile->update($changes);
        } elseif ($event['type'] === 'profile.qr.updated') {
            $command = AgentCommand::find($event['command_id']);
            if (now()->lt($payload['expires_at']) && $command && in_array($command->status, ['leased', 'running'], true) && $command->lease_expires_at?->gt(now())) {
                $profile->update(['qr' => $payload, 'status' => 'awaiting_qr']);
            }
        } elseif ($event['type'] === 'command.late_result') {
            $command = AgentCommand::find($event['command_id']);
            if ($command && in_array($command->status, ['leased', 'running', 'unknown'], true) && $command->completionExpiresAt()->gt(now())) {
                // Apply the same validation and follow-up work as a normal result, within 12 hours.
                app(AgentController::class)->result(\Illuminate\Http\Request::create('/', 'POST', $payload), $command->id);
            }
        } else {
            $kind = ['message.received' => 'messages', 'message.delivery.updated' => 'delivery', 'friend.request.received' => 'friend_requests'][$event['type']];
            $providerId = $payload['message_id'] ?? $payload['request_id'];
            $existing = DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', $kind)->where('group_id', '')->where('provider_id', $providerId)->first();
            if ($event['type'] === 'message.received' && $existing) {
                return;
            }
            $values = ['data' => json_encode($payload), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()];
            if ($event['type'] === 'message.received') {
                $values += [
                    'conversation_type' => $payload['conversation']['type'], 'conversation_id' => $payload['conversation']['id'],
                    'sender_id' => $payload['sender_id'],
                    'message_sent_at' => \Carbon\Carbon::parse($payload['sent_at'])->setTimezone(config('app.timezone')),
                    'read_at' => ($payload['historical'] ?? false) || $payload['sender_id'] === $profile->zalo_user_id ? now() : null,
                ];
            }
            DB::table('agent_records')->updateOrInsert(['profile_id' => $profile->id, 'kind' => $kind, 'group_id' => '', 'provider_id' => $providerId], $values);
            app(PlatformEngine::class)->incoming($event, $profile);
        }
    }
}
