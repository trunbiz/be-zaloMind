<?php

namespace App\Http\Controllers;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Services\AgentEvents;
use App\Support\AgentApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    public const TYPES = ['profile.login_qr', 'profile.logout', 'message.send', 'contact.resolve', 'contacts.sync', 'groups.sync', 'group.members.sync', 'friend.request', 'friend.accept', 'group.invite', 'group.join', 'group.leave', 'group.inspect_link'];

    public function config(Request $request)
    {
        return AgentApi::ok([
            'enabled' => true, 'heartbeat_interval_seconds' => 30, 'offline_after_seconds' => 90,
            'lease_seconds' => AgentCommand::COMPLETION_TIMEOUT_SECONDS, 'command_timeout_seconds' => AgentCommand::COMPLETION_TIMEOUT_SECONDS,
            'execution_managed_by' => 'app', 'max_concurrency' => $request->attributes->get('agent_runtime')->max_concurrency,
            'poll_interval_seconds' => 5, 'rate_limit_per_minute' => 120,
            'event_batch_limit' => 100, 'snapshot_page_limit' => 500,
        ]);
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'app_version' => 'required|string|max:100', 'protocol_version' => ['required', Rule::in(['1'])],
            'capabilities' => 'present|array|max:50', 'capabilities.*' => ['string', 'distinct', Rule::in(self::TYPES)],
            'capacity' => 'required|integer|min:0|max:100', 'running_command_ids' => 'present|array|max:100',
            'running_command_ids.*' => 'uuid|distinct', 'health.status' => ['required', Rule::in(['healthy', 'degraded', 'unhealthy'])],
            'health.queue_depth' => 'required|integer|min:0',
        ]);
        DB::table('agent_runtime')->where('id', 1)->update(['last_heartbeat_at' => now(), 'heartbeat' => json_encode($data)]);
        app(AgentEvents::class)->cleanupQr();
        app(\App\Services\PlatformEngine::class)->expireCommands();

        return AgentApi::ok(['accepted' => true, 'server_time' => now()->toISOString(), 'config_changed' => false]);
    }

    public function profiles(Request $request)
    {
        $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|string|max:1000']);
        $page = AgentProfile::withTrashed()->where(fn ($query) => $query->whereNull('deleted_at')->orWhere(fn ($pending) => $pending->where('connection_intent', 'logout')->where('status', '!=', 'disconnected')))->orderBy('id')->cursorPaginate($request->integer('limit', 50));
        $items = collect($page->items())->map(function ($profile) {
            $proxy = $profile->proxy_id ? \App\Models\WorkspaceItem::where('kind', 'proxy')->find($profile->proxy_id) : null;

            return array_merge($profile->only(['id', 'assignment_version', 'display_name', 'status', 'zalo_user_id']), ['proxy' => $proxy?->data, 'deleted' => $profile->trashed(), 'desired_state' => match ($profile->connection_intent) {
                'logout' => 'disconnected', 'login' => 'connected', default => null
            }]);
        })->all();

        return AgentApi::ok($items, 200, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    public function claim(Request $request)
    {
        $data = $request->validate(['limit' => 'required|integer|min:1|max:100']);
        $runtime = $request->attributes->get('agent_runtime');
        $heartbeat = json_decode($runtime->heartbeat ?? '{}', true);
        app(\App\Services\PlatformEngine::class)->expireCommands();
        AgentCommand::where('status', 'queued')->whereNotNull('deadline_at')->where('deadline_at', '<=', now())
            ->update(['status' => 'cancelled', 'finished_at' => now()]);
        if (! $runtime->last_heartbeat_at || now()->diffInSeconds($runtime->last_heartbeat_at) > 90 || ($heartbeat['health']['status'] ?? '') === 'unhealthy') {
            return AgentApi::ok([]);
        }
        // The App owns its durable queue and serializes execution. limit is a delivery batch size.
        $commands = AgentCommand::where('status', 'queued')->where('cancel_requested', false)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->whereIn('type', $heartbeat['capabilities'] ?? [])->orderByRaw("CASE WHEN type IN ('profile.login_qr', 'profile.logout') THEN 0 ELSE 1 END")->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $claimed = [];
        foreach ($commands as $command) {
            if (count($claimed) >= $data['limit']) {
                break;
            }
            $profile = AgentProfile::withTrashed()->find($command->profile_id);
            if (! $profile || $profile->assignment_version !== $command->assignment_version) {
                continue;
            }
            if (! app(\App\Services\PlatformEngine::class)->canClaim($command, $profile)) {
                continue;
            }
            $command->update([
                'status' => 'leased', 'attempt' => $command->attempt + 1,
                'lease_token' => Str::random(64), 'dispatched_at' => now(),
                'lease_expires_at' => now()->addSeconds(AgentCommand::COMPLETION_TIMEOUT_SECONDS),
                'progress_seq' => 0, 'progress_percent' => 0,
            ]);
            $claimed[] = [
                'id' => $command->id, 'attempt' => $command->attempt, 'type' => $command->type,
                'profile_id' => $command->profile_id, 'assignment_version' => $command->assignment_version,
                'execution_key' => $command->execution_key, 'lease_token' => $command->lease_token,
                'lease_expires_at' => $command->lease_expires_at->toISOString(),
                'deadline_at' => $command->deadline_at?->toISOString(), 'payload' => (object) $command->payload,
            ];
        }

        return AgentApi::ok($claimed);
    }

    public function profile(string $id, ?int $assignment = null, bool $includeDeleted = false): AgentProfile
    {
        $profile = ($includeDeleted ? AgentProfile::withTrashed() : AgentProfile::query())->find($id);
        if (! $profile) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Profile không tồn tại.', 404);
        }
        if ($assignment !== null && $profile->assignment_version !== $assignment) {
            AgentApi::fail('STALE_ASSIGNMENT', 'Phiên giao profile đã thay đổi.');
        }

        return $profile;
    }

    public function activeCommand(Request $request, string $id): AgentCommand
    {
        $data = $request->validate(['lease_token' => 'required|string|max:200', 'attempt' => 'required|integer|min:1', 'assignment_version' => 'required|integer|min:1']);
        $command = AgentCommand::find($id);
        if (! $command) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Command không tồn tại.', 404);
        }
        $this->profile($command->profile_id, $data['assignment_version'], true);
        if ($command->assignment_version !== $data['assignment_version']) {
            AgentApi::fail('STALE_ASSIGNMENT', 'Phiên command đã thay đổi.');
        }
        if (! in_array($command->status, ['leased', 'running', 'unknown'], true) || $command->completionExpiresAt()->lte(now()) || $command->attempt !== $data['attempt'] || ! hash_equals((string) $command->lease_token, $data['lease_token'])) {
            AgentApi::fail('LEASE_EXPIRED', 'Lease sai, đã kết thúc hoặc hết hạn.');
        }

        return $command;
    }

    public function lease(Request $request, string $id)
    {
        $command = $this->activeCommand($request, $id);
        if (! $command->cancel_requested) {
            $command->update(['lease_expires_at' => $command->completionExpiresAt()]);
        }

        return AgentApi::ok(['lease_expires_at' => $command->lease_expires_at->toISOString(), 'cancel_requested' => $command->cancel_requested || ($command->deadline_at && $command->deadline_at->lte(now()))]);
    }

    public function progress(Request $request, string $id)
    {
        $data = $request->validate(['progress_seq' => 'required|integer|min:1', 'progress_percent' => 'required|integer|min:0|max:100', 'stage' => 'required|string|max:100']);
        $command = $this->activeCommand($request, $id);
        if ($data['progress_seq'] > $command->progress_seq) {
            $command->update($data + ['status' => 'running', 'started_at' => $command->started_at ?? now()]);
        }

        return AgentApi::ok(['accepted' => true]);
    }

    public function result(Request $request, string $id)
    {
        $data = $request->validate([
            'execution_key' => 'required|uuid', 'status' => ['required', Rule::in(['succeeded', 'failed', 'cancelled', 'unknown'])],
            'started_at' => 'required|date', 'finished_at' => 'required|date|after_or_equal:started_at',
            'output' => 'present|nullable|array', 'error' => 'present|nullable|array',
            'error.code' => 'required_if:status,failed,unknown|string|max:100',
            'error.message' => 'required_if:status,failed,unknown|string|max:2000',
            'error.retryable' => 'required_if:status,failed,unknown|boolean',
            'error.provider_code' => 'nullable|string|max:200',
        ]);
        $command = $this->activeCommand($request, $id);
        if ($command->execution_key !== $data['execution_key']) {
            AgentApi::fail('INVALID_STATE', 'execution_key không khớp.');
        }
        if ($data['status'] === 'succeeded' && $data['error'] !== null) {
            AgentApi::fail('VALIDATION_FAILED', 'Kết quả succeeded phải có error=null.', 422);
        }
        if ($data['status'] === 'succeeded' && $command->type === 'message.send') {
            $request->validate(['output.provider_message_id' => 'required|string|max:191']);
        }
        if ($data['status'] === 'succeeded' && $command->type === 'contact.resolve') {
            $request->validate(['output.zalo_user_id' => 'required|string|max:191']);
        }
        if ($data['status'] === 'succeeded' && str_ends_with($command->type, '.sync')) {
            $request->validate(['output.sync_id' => 'required|uuid', 'output.count' => 'required|integer|min:0']);
            $kind = ['contacts.sync' => 'contacts', 'groups.sync' => 'groups', 'group.members.sync' => 'group_members'][$command->type];
            $sync = DB::table('agent_snapshots')->where('id', $data['output']['sync_id'])->where('profile_id', $command->profile_id)->where('assignment_version', $command->assignment_version)->where('kind', $kind)->where('status', 'completed')->first();
            if (! $sync || $sync->item_count !== $data['output']['count'] || ($kind === 'group_members' && $sync->group_id !== ($command->payload['group_id'] ?? ''))) {
                AgentApi::fail('INVALID_STATE', 'Snapshot chưa hoàn tất hoặc không khớp command.');
            }
        }
        $profile = $this->profile($command->profile_id, $command->assignment_version, true);
        $currentControl = app(\App\Services\ProfileLifecycle::class)->isCurrent($command, $profile);
        if ($data['status'] === 'succeeded' && $command->type === 'profile.login_qr') {
            $request->validate(['output.zalo_user_id' => 'required|string|max:191', 'output.display_name' => 'required|string|max:255', 'output.avatar_url' => 'nullable|url|max:2048']);
            if ($currentControl && ! $profile->trashed() && $profile->connection_intent !== 'logout') {
                $profile->update(['status' => 'connected', 'zalo_user_id' => $data['output']['zalo_user_id'], 'display_name' => $data['output']['display_name'], 'avatar_url' => $data['output']['avatar_url'] ?? null, 'qr' => null, 'last_seen_at' => now()]);
            }
        }
        if ($data['status'] === 'succeeded' && $command->type === 'profile.logout') {
            $request->validate(['output.logged_out' => 'required|accepted']);
            if ($currentControl) {
                $profile->update(['status' => 'disconnected', 'qr' => null]);
            }
        }
        if ($command->type === 'profile.login_qr' && $data['status'] !== 'succeeded' && $currentControl && ! $profile->trashed()) {
            $profile->update(['status' => 'disconnected', 'qr' => null]);
        }
        if ($command->type === 'profile.logout' && $data['status'] !== 'succeeded' && $currentControl) {
            $profile->update(['status' => 'error', 'qr' => null]);
        }
        $command->update(['status' => $data['status'], 'result' => $data, 'started_at' => \Carbon\Carbon::parse($data['started_at'])->setTimezone(config('app.timezone')), 'finished_at' => \Carbon\Carbon::parse($data['finished_at'])->setTimezone(config('app.timezone'))]);
        app(\App\Services\PlatformEngine::class)->finished($command);

        return AgentApi::ok(['command_id' => $id, 'status' => $command->status, 'duplicate' => false]);
    }

    public function events(Request $request, AgentEvents $events)
    {
        $request->validate(['events' => 'required|array|min:1|max:100']);
        $results = [];
        foreach ($request->input('events') as $event) {
            try {
                $results[] = DB::transaction(fn () => $events->receive($event));
            } catch (\Illuminate\Validation\ValidationException $e) {
                $results[] = ['event_id' => is_array($event) ? ($event['event_id'] ?? null) : null, 'status' => 'rejected', 'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Sự kiện không hợp lệ.', 'fields' => $e->errors(), 'retryable' => false]];
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                $results[] = ['event_id' => is_array($event) ? ($event['event_id'] ?? null) : null, 'status' => 'rejected', 'error' => $e->getResponse()->getData(true)['error']];
            }
        }

        return AgentApi::ok(['results' => $results]);
    }

    public function createSnapshot(Request $request, string $id)
    {
        $data = $request->validate(['assignment_version' => 'required|integer|min:1', 'kind' => ['required', Rule::in(['contacts', 'groups', 'group_members'])], 'mode' => ['required', Rule::in(['full'])], 'group_id' => 'required_if:kind,group_members|nullable|string|max:191']);
        $this->profile($id, $data['assignment_version']);
        $group = $data['kind'] === 'group_members' ? $data['group_id'] : '';
        if (DB::table('agent_snapshots')->where('profile_id', $id)->where('kind', $data['kind'])->where('group_id', $group)->where('status', 'staging')->where('expires_at', '>', now())->exists()) {
            AgentApi::fail('INVALID_STATE', 'Đang có snapshot cùng loại.');
        }
        $syncId = (string) Str::uuid();
        $expires = now()->addHour();
        DB::table('agent_snapshots')->insert(['id' => $syncId, 'profile_id' => $id, 'assignment_version' => $data['assignment_version'], 'kind' => $data['kind'], 'group_id' => $group, 'status' => 'staging', 'item_count' => 0, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return AgentApi::ok(['sync_id' => $syncId, 'page_limit' => 500, 'expires_at' => $expires->toISOString()], 201);
    }

    private function snapshot(string $id, string $syncId)
    {
        $sync = DB::table('agent_snapshots')->where('id', $syncId)->where('profile_id', $id)->first();
        if (! $sync) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Snapshot không tồn tại.', 404);
        }
        $this->profile($id, $sync->assignment_version);
        if ($sync->status !== 'staging' || now()->gte($sync->expires_at)) {
            AgentApi::fail('INVALID_STATE', 'Snapshot đã hoàn tất hoặc hết hạn.');
        }

        return $sync;
    }

    public function snapshotPage(Request $request, string $id, string $syncId, string $page)
    {
        if (! ctype_digit($page) || (int) $page < 1 || (int) $page > 10000) {
            AgentApi::fail('VALIDATION_FAILED', 'Page phải từ 1 đến 10000.', 422);
        }
        $sync = $this->snapshot($id, $syncId);
        $rules = ['items' => 'required|array|min:1|max:500'];
        $idField = $sync->kind === 'groups' ? 'group_id' : 'zalo_user_id';
        $rules['items.*.'.$idField] = 'required|string|max:191|distinct';
        $rules['items.*.'.($sync->kind === 'groups' ? 'name' : 'display_name')] = 'required|string|max:255';
        $rules['items.*.avatar_url'] = 'nullable|url|max:2048';
        if ($sync->kind === 'contacts') {
            $rules['items.*.phone'] = 'nullable|string|max:30';
        } elseif ($sync->kind === 'groups') {
            $rules['items.*.member_count'] = 'required|integer|min:0';
        } else {
            $rules['items.*.role'] = ['required', Rule::in(['owner', 'admin', 'member', 'unknown'])];
        }
        $items = $request->validate($rules)['items'];
        $hash = AgentApi::hash($items);
        $existing = DB::table('agent_snapshot_pages')->where('snapshot_id', $syncId)->where('page', $page)->first();
        if ($existing && $existing->body_hash !== $hash) {
            AgentApi::fail('IDEMPOTENCY_CONFLICT', 'Trang đã tồn tại với nội dung khác.');
        }
        if (! $existing) {
            $count = DB::table('agent_snapshot_pages')->where('snapshot_id', $syncId)->count();
            if ((int) $page !== $count + 1) {
                AgentApi::fail('INVALID_STATE', 'Phải gửi trang liên tiếp từ 1.');
            }
            DB::table('agent_snapshot_pages')->insert(['snapshot_id' => $syncId, 'page' => $page, 'body_hash' => $hash, 'items' => json_encode($items)]);
        }

        return AgentApi::ok(['page' => (int) $page, 'received_count' => count($items)]);
    }

    public function completeSnapshot(Request $request, string $id, string $syncId)
    {
        $data = $request->validate(['total_pages' => 'required|integer|min:0|max:10000', 'total_items' => 'required|integer|min:0']);
        $sync = $this->snapshot($id, $syncId);
        $pages = DB::table('agent_snapshot_pages')->where('snapshot_id', $syncId)->orderBy('page')->get();
        $items = $pages->flatMap(fn ($page) => json_decode($page->items, true))->all();
        if ($pages->count() !== $data['total_pages'] || count($items) !== $data['total_items']) {
            AgentApi::fail('INVALID_STATE', 'Tổng số trang hoặc item không khớp.');
        }
        $idField = $sync->kind === 'groups' ? 'group_id' : 'zalo_user_id';
        if (count(array_unique(array_column($items, $idField), SORT_STRING)) !== count($items)) {
            AgentApi::fail('INVALID_STATE', 'ID trùng giữa các trang.');
        }
        DB::table('agent_records')->where('profile_id', $id)->where('kind', $sync->kind)->where('group_id', $sync->group_id)->delete();
        foreach (array_chunk($items, 200) as $chunk) {
            DB::table('agent_records')->insert(array_map(fn ($item) => ['profile_id' => $id, 'kind' => $sync->kind, 'group_id' => $sync->group_id, 'provider_id' => $item[$idField], 'data' => json_encode($item), 'created_at' => now(), 'updated_at' => now()], $chunk));
        }
        DB::table('agent_snapshots')->where('id', $syncId)->update(['status' => 'completed', 'item_count' => count($items), 'updated_at' => now()]);

        return AgentApi::ok(['sync_id' => $syncId, 'status' => 'completed', 'count' => count($items)]);
    }

    public function download(string $id)
    {
        $attachment = DB::table('agent_attachments')->where('id', $id)->first();
        if (! $attachment) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Tệp không tồn tại.', 404);
        }
        $command = AgentCommand::find($attachment->command_id);
        if (! $command || ! in_array($command->status, ['leased', 'running'], true) || $command->completionExpiresAt()->lte(now())) {
            AgentApi::fail('LEASE_EXPIRED', 'Tệp không thuộc command có lease còn hạn.');
        }
        $this->profile($command->profile_id, $command->assignment_version);
        if (($command->payload['content']['attachment_id'] ?? null) !== $id || ! in_array($attachment->disk, ['local'], true) || str_contains($attachment->path, '..') || str_starts_with($attachment->path, '/') || str_contains($attachment->path, ':')) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Tệp không được cấp.', 404);
        }
        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->path)) {
            AgentApi::fail('RESOURCE_NOT_FOUND', 'Tệp không tồn tại.', 404);
        }

        return $disk->response($attachment->path, null, ['Content-Type' => $attachment->mime_type, 'Content-Length' => (string) $disk->size($attachment->path), 'X-Content-SHA256' => $attachment->sha256]);
    }
}
