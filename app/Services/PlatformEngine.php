<?php

namespace App\Services;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformEngine
{
    public function __construct(private Platform $platform) {}

    public function tick(): void
    {
        DB::transaction(function () {
            DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
            Order::where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired', 'version' => DB::raw('version + 1')]);
            Subscription::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);
            $this->expireCommands();
            foreach (WorkspaceItem::where('kind', 'automation')->where('status', 'active')->get() as $rule) {
                $data = $rule->data;
                if ($this->platform->settings()['maintenance_mode'] || $data['mode'] === 'incoming' || Carbon::parse($data['next_run_at'])->gt(now())) {
                    continue;
                }
                try {
                    DB::transaction(function () use ($rule, $data) {
                        $owner = User::findOrFail($rule->user_id);
                        $profileIds = $data['profile_ids'] ?? [$data['profile_id']];
                        $profileId = $profileIds[($data['rotation_index'] ?? 0) % count($profileIds)];
                        $profile = $this->platform->own(AgentProfile::class, $profileId, $owner);
                        $payload = $data + ['name' => $rule->title, 'interval_seconds' => 5, 'text' => $data['steps'][0]['text']];
                        $this->platform->campaign($owner, $profile, $payload, $data['source'], $rule->id.':'.$data['next_run_at'], $data['steps']);
                    });
                    $data['last_run_at'] = now()->toISOString();
                    $data['last_error'] = null;
                    $data['rotation_index'] = ($data['rotation_index'] ?? 0) + 1;
                    $data['next_run_at'] = $data['mode'] === 'birthdays' ? now()->addDay()->startOfDay()->addHours(2)->toISOString() : now()->addMinutes($data['interval_minutes'])->toISOString();
                    $rule->update(['data' => $data, 'status' => $data['mode'] === 'drip' ? 'completed' : 'active', 'version' => $rule->version + 1]);
                } catch (ValidationException $e) {
                    $data['last_error'] = $e->getMessage();
                    // Birthday days with no recipients are normal; try again tomorrow.
                    if ($data['mode'] === 'birthdays' && str_contains($e->getMessage(), 'người nhận')) {
                        $data['next_run_at'] = now()->addDay()->startOfDay()->addHours(2)->toISOString();
                        $rule->update(['data' => $data, 'version' => $rule->version + 1]);
                    } else {
                        $rule->update(['status' => 'error', 'data' => $data, 'version' => $rule->version + 1]);
                    }
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    $data['last_error'] = 'Không tìm thấy tài khoản hoặc profile thuộc chủ sở hữu kịch bản.';
                    $rule->update(['status' => 'error', 'data' => $data, 'version' => $rule->version + 1]);
                }
            }
            foreach (AgentCommand::where('status', 'succeeded')->whereNotNull('workflow')->where(fn ($query) => $query->whereNull('workflow->done')->orWhere('workflow->done', false))->whereNotIn('id', AgentCommand::whereNotNull('parent_command_id')->select('parent_command_id'))->limit(100)->get() as $command) {
                $this->completed($command);
            }
            foreach (Campaign::whereIn('status', ['scheduled', 'running'])->get() as $campaign) {
                $pending = AgentCommand::where('campaign_id', $campaign->id)->whereIn('status', ['queued', 'pending_approval', 'leased', 'running', 'unknown'])->exists();
                $followup = AgentCommand::where('campaign_id', $campaign->id)->where('status', 'succeeded')->whereNotNull('workflow')->get()->contains(fn ($command) => ! empty($command->result['workflow_error']));
                $campaign->update(['status' => $pending || $followup ? 'running' : 'completed']);
            }
        });
    }

    // Callers hold the agent_runtime row lock, also used by claim/result.
    public function expireCommands(): void
    {
        AgentCommand::whereIn('status', ['leased', 'running', 'unknown'])
            ->whereRaw('COALESCE(dispatched_at, started_at, created_at) <= ?', [now()->subSeconds(AgentCommand::COMPLETION_TIMEOUT_SECONDS)])
            ->chunkById(200, function ($commands) {
                foreach ($commands as $command) {
                    $command->update(['status' => 'failed', 'finished_at' => now(), 'version' => $command->version + 1, 'result' => [
                        'status' => 'failed', 'output' => null,
                        'error' => ['code' => 'COMMAND_TIMEOUT', 'message' => 'App chưa hoàn thành lệnh sau 12 tiếng kể từ khi được giao.', 'retryable' => false],
                    ]]);
                    $profile = AgentProfile::withTrashed()->find($command->profile_id);
                    if ($profile && in_array($command->type, ProfileLifecycle::CONTROLS, true) && app(ProfileLifecycle::class)->isCurrent($command, $profile)) {
                        $profile->update(['status' => 'error', 'qr' => null]);
                    }
                    $this->finished($command);
                }
            });
    }

    public function canClaim(AgentCommand $command, AgentProfile $profile): bool
    {
        $control = in_array($command->type, ProfileLifecycle::CONTROLS, true);
        if (($profile->trashed() || $profile->connection_intent === 'logout') && $command->type !== 'profile.logout') {
            return false;
        }
        if ($control && $profile->control_command_id && $profile->control_command_id !== $command->id) {
            return false;
        }
        // Logout is cleanup, including suspended users, maintenance and deleted profiles.
        if ($command->type === 'profile.logout') {
            return true;
        }
        $owner = User::find($profile->user_id);
        if (! $owner || $owner->status !== 'active' || $this->platform->settings()['maintenance_mode']) {
            return false;
        }
        if ($command->quota_units && $command->available_at?->isSameDay(now()) && $this->platform->used($owner) > $this->platform->limits($owner)['messages_per_day']) {
            return false;
        }
        if ($command->campaign_id && ! Campaign::where('id', $command->campaign_id)->whereIn('status', ['scheduled', 'running'])->exists()) {
            return false;
        }
        $id = $command->payload['recipient']['id'] ?? $command->payload['phone'] ?? $command->payload['target']['value'] ?? null;
        if ($id && $this->platform->optedOut($owner, $id)) {
            $command->update(['status' => 'cancelled', 'finished_at' => now()]);

            return false;
        }
        if (! in_array($command->type, ['profile.login_qr', 'profile.logout'], true) && $profile->status !== 'connected') {
            return false;
        }
        if ($command->quota_units && $command->available_at && ! $command->available_at->isSameDay(now())) {
            if ($this->platform->used($owner) + $command->quota_units > $this->platform->limits($owner)['messages_per_day']) {
                return false;
            }
            $command->update(['available_at' => now()]);
        }

        return true;
    }

    public function completed(AgentCommand $command): void
    {
        if ($command->status !== 'succeeded' || ! $command->workflow || ($command->workflow['done'] ?? false) || AgentCommand::where('parent_command_id', $command->id)->exists()) {
            return;
        }
        $result = $command->result;
        try {
            DB::transaction(function () use ($command) {
                $workflow = $command->workflow;
                $output = $command->result['output'] ?? [];
                $profile = AgentProfile::withTrashed()->findOrFail($command->profile_id);
                if ($profile->trashed() || $profile->connection_intent === 'logout') {
                    $workflow['done'] = true;
                    $command->update(['workflow' => $workflow]);

                    return;
                }
                $owner = User::findOrFail($profile->user_id);
                if ($command->campaign_id && Campaign::where('id', $command->campaign_id)->where('status', 'cancelled')->exists()) {
                    $workflow['done'] = true;
                    $command->update(['workflow' => $workflow]);

                    return;
                }
                if ($command->campaign_id && ! Campaign::where('id', $command->campaign_id)->whereIn('status', ['scheduled', 'running'])->exists()) {
                    $this->platform->error('Chiến dịch đang tạm dừng hoặc đã hủy.');
                }
                $campaign = $command->campaign_id ? Campaign::find($command->campaign_id) : null;
                if (($workflow['next'] ?? '') === 'message.send') {
                    if (empty($output['zalo_user_id']) || ! is_string($output['zalo_user_id']) || strlen($output['zalo_user_id']) > 191) {
                        $this->platform->error('Agent chưa trả zalo_user_id hợp lệ cho số điện thoại.');
                    }
                    if ($this->platform->optedOut($owner, $command->payload['phone'])) {
                        $workflow['done'] = true;
                        $command->update(['workflow' => $workflow]);

                        return;
                    }
                    if ($command->available_at && ! $command->available_at->isSameDay(now())) {
                        if ($this->platform->used($owner) + 1 > $this->platform->limits($owner)['messages_per_day']) {
                            $this->platform->error('Chưa đủ hạn mức ngày hiện tại để gửi sau khi tìm số.');
                        }
                        $command->update(['available_at' => now()]);
                    }
                    $child = $this->platform->queue($owner, $profile, 'message.send', ['recipient' => ['type' => 'user', 'id' => $output['zalo_user_id']], 'content' => $workflow['content']], null, $campaign, [], 0);
                    $child->update(['parent_command_id' => $command->id]);
                } elseif (($workflow['next'] ?? '') === 'group.members.sync') {
                    if (empty($output['valid']) || empty($output['group_id']) || ! is_string($output['group_id']) || strlen($output['group_id']) > 191) {
                        $this->platform->error('Link nhóm không hợp lệ hoặc agent chưa trả ID nhóm.');
                    }
                    $child = $this->platform->queue($owner, $profile, 'group.members.sync', ['group_id' => $output['group_id'], 'mode' => 'full'], null, null, ['import_list' => $workflow['import_list']]);
                    $child->update(['parent_command_id' => $command->id]);
                } elseif (isset($workflow['friend_record_id'])) {
                    $record = DB::table('agent_records')->where('id', $workflow['friend_record_id'])->where('profile_id', $profile->id)->where('kind', 'friend_requests')->first();
                    if ($record) {
                        $payload = json_decode($record->data, true);
                        $payload['accepted_at'] = now()->toISOString();
                        DB::table('agent_records')->where('id', $record->id)->update(['data' => json_encode($payload)]);
                    }
                } elseif (isset($workflow['import_list'])) {
                    foreach (DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', 'group_members')->where('group_id', $command->payload['group_id'])->get() as $record) {
                        $contact = json_decode($record->data, true);
                        $existing = WorkspaceItem::where('kind', 'contact')->where('user_id', $owner->id)->get()->first(fn ($item) => ($item->data['zalo_user_id'] ?? null) === $contact['zalo_user_id'] && ($item->data['list_name'] ?? '') === $workflow['import_list']);
                        if (! $existing) {
                            WorkspaceItem::create(['user_id' => $owner->id, 'kind' => 'contact', 'title' => $contact['display_name'], 'data' => ['zalo_user_id' => $contact['zalo_user_id'], 'list_name' => $workflow['import_list'], 'labels' => '', 'phone' => null, 'birthday' => null]]);
                        }
                    }
                }
                $workflow['done'] = true;
                $command->update(['workflow' => $workflow]);
            });
            unset($result['workflow_error']);
        } catch (ValidationException $e) {
            $result['workflow_error'] = $e->getMessage();
        }
        $command->update(['result' => $result]);
    }

    public function finished(AgentCommand $command): void
    {
        if (in_array($command->status, ['failed', 'cancelled'], true) && isset($command->workflow['friend_record_id'])) {
            $record = DB::table('agent_records')->where('id', $command->workflow['friend_record_id'])->where('profile_id', $command->profile_id)->where('kind', 'friend_requests')->first();
            if ($record) {
                $data = json_decode($record->data, true);
                $data['accept_requested'] = false;
                DB::table('agent_records')->where('id', $record->id)->update(['data' => json_encode($data)]);
            }
        }
        $this->completed($command);
    }

    public function incoming(array $event, AgentProfile $profile): void
    {
        if ($event['type'] !== 'message.received' || ($event['payload']['historical'] ?? false) || $event['payload']['sender_id'] === $profile->zalo_user_id) {
            return;
        }
        $sender = $event['payload']['sender_id'];
        $contact = WorkspaceItem::where('user_id', $profile->user_id)->where('kind', 'contact')->get()->first(fn ($item) => ($item->data['zalo_user_id'] ?? null) === $sender);
        if (! $contact) {
            return;
        }
        foreach (WorkspaceItem::where('user_id', $profile->user_id)->where('kind', 'automation')->where('status', 'active')->get() as $rule) {
            $data = $rule->data;
            if ($data['mode'] !== 'incoming' || $data['profile_id'] !== $profile->id || ! in_array($data['label'], array_map('trim', explode(',', $contact->data['labels'] ?? '')), true)) {
                continue;
            }
            $key = $rule->id.':'.$sender;
            $receipt = WorkspaceItem::where('kind', 'automation_receipt')->where('user_id', $profile->user_id)->where('title', $key)->first();
            if ($receipt && Carbon::parse($receipt->data['last_at'])->addMinutes($data['interval_minutes'])->gt(now())) {
                continue;
            }
            try {
                DB::transaction(function () use ($data, $profile, $event, $receipt, $key) {
                    $owner = User::findOrFail($profile->user_id);
                    foreach ($data['steps'] as $step) {
                        $this->platform->queue($owner, $profile, 'message.send', ['recipient' => $event['payload']['conversation'], 'content' => ['type' => 'text', 'text' => $step['text']]], now()->addMinutes($step['delay_minutes']));
                    }
                    if ($receipt) {
                        $receipt->update(['data' => ['last_at' => now()->toISOString()]]);
                    } else {
                        WorkspaceItem::create(['user_id' => $profile->user_id, 'kind' => 'automation_receipt', 'title' => $key, 'data' => ['last_at' => now()->toISOString()]]);
                    }
                });
            } catch (ValidationException $e) {
                $data['last_error'] = $e->getMessage();
                $rule->update(['data' => $data, 'status' => 'error', 'version' => $rule->version + 1]);
            }
        }
    }
}
