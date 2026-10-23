<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class WorkspaceActions
{
    public function __construct(private Platform $platform) {}

    public function run(User $actor, string $section, string $action, Request $request, bool $admin = false): array
    {
        $p = $this->platform;
        $data = $request->all();
        $validate = fn (array $rules) => $request->validate($rules);
        $reason = $request->input('reason', 'Thao tác từ giao diện quản trị');
        if ($admin) {
            abort_unless($actor->hasRole('admin'), 403);
            $validate(['reason' => 'sometimes|required|string|min:5|max:1000']);
        }
        $id = (string) $request->input('id');
        $result = ['message' => 'Đã lưu thay đổi.'];
        if ($section === 'message-birthdays' && $action === 'create-rule') {
            return $this->run($actor, 'message-birthdays-rules', 'create', $request, false);
        }
        if ($section === 'message-birthdays' && str_starts_with($action, 'rule-')) {
            return $this->run($actor, 'message-birthdays-rules', substr($action, 5), $request, false);
        }
        if ($section === 'profiles') {
            if ($action === 'create') {
                $validate(['display_name' => 'required|string|max:255']);
                $result['record'] = $p->createProfile($actor, $data['display_name'])->toArray();
            } else {
                $profile = $p->own(AgentProfile::class, $id, $actor, $admin);
                $owner = User::findOrFail($profile->user_id);
                if (in_array($action, ['login', 'logout', 'delete'], true)) {
                    return app(ProfileLifecycle::class)->request($owner, $profile, $action, $actor);
                }
                $p->version($profile, $data);
                if ($action === 'rename') {
                    $validate(['display_name' => 'required|string|max:255']);
                    $profile->update(['display_name' => $data['display_name'], 'version' => $profile->version + 1]);
                } elseif (in_array($action, ['sync-contacts', 'sync-groups'], true)) {
                    $type = ['login' => 'profile.login_qr', 'logout' => 'profile.logout', 'sync-contacts' => 'contacts.sync', 'sync-groups' => 'groups.sync'][$action];
                    $payload = str_ends_with($type, '.sync') ? ['mode' => 'full'] : [];
                    $command = $p->queue($owner, $profile, $type, $payload);
                    $profile->increment('version');
                    $result = ['message' => 'Đã đưa lệnh vào hàng đợi. Agent sẽ trả kết quả sau khi xử lý.', 'record' => $command->toArray()];
                } elseif ($action === 'proxy' && $admin) {
                    $validate(['proxy_id' => 'nullable|uuid']);
                    if (! empty($data['proxy_id'])) {
                        WorkspaceItem::where('kind', 'proxy')->whereNull('user_id')->findOrFail($data['proxy_id']);
                    }
                    $profile->update(['proxy_id' => $data['proxy_id'] ?? null, 'version' => $profile->version + 1]);
                } else {
                    abort(404);
                }
            }
        } elseif (str_starts_with($section, 'message-')) {
            if ($action !== 'create') {
                return $this->campaignAction($actor, $id, $action, $data);
            }
            $validate(['profile_id' => 'required|uuid', 'name' => 'required|string|max:255', 'text' => 'required|string|max:2000', 'recipient_ids' => 'nullable|string|max:50000', 'source_value' => 'nullable|string|max:255', 'scheduled_at' => 'nullable|date', 'interval_seconds' => 'required|integer|min:1|max:3600']);
            $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
            if (isset($data['recipient_selection']) || isset($data['selected_recipients'])) {
                $validate(['recipient_selection' => 'sometimes|in:selected', 'selected_recipients' => 'required|array|min:1|max:1000', 'selected_recipients.*' => 'required|string|distinct|max:20']);
            }
            $campaign = $p->campaign($actor, $profile, $data, $section);
            $result = ['message' => 'Đã tạo chiến dịch. Kết quả gửi được cập nhật từ agent.', 'record' => $campaign->toArray()];
        } elseif ($section === 'friends') {
            abort_unless($action === 'sync', 404);
            $validate(['profile_id' => 'required|uuid']);
            $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
            $result['record'] = $p->queue($actor, $profile, 'contacts.sync', ['mode' => 'full'])->toArray();
        } elseif ($section === 'friend-invites') {
            abort_unless($action === 'create', 404);
            $validate(['profile_id' => 'required|uuid', 'phones' => 'required|string|max:10000', 'message' => 'nullable|string|max:500']);
            $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
            $phones = preg_split('/[\s,;]+/', $data['phones'], -1, PREG_SPLIT_NO_EMPTY);
            if (count($phones) > 200) {
                $p->error('Mỗi lượt tối đa 200 số điện thoại.');
            }
            foreach (array_unique($phones) as $phone) {
                if (! preg_match('/^\+?[0-9]{9,15}$/D', $phone)) {
                    $p->error('Số điện thoại không hợp lệ: '.$phone);
                }
                $p->queue($actor, $profile, 'friend.request', ['target' => ['type' => 'phone', 'value' => $phone], 'message' => $data['message'] ?? '']);
            }
            $result['message'] = 'Đã tạo các lệnh kết bạn.';
        } elseif ($section === 'friend-requests') {
            abort_unless($action === 'accept', 404);
            $record = DB::table('agent_records')->where('id', $id)->where('kind', 'friend_requests')->first();
            abort_unless($record, 404);
            $profile = $p->own(AgentProfile::class, $record->profile_id, $actor);
            $payload = json_decode($record->data, true);
            if (! empty($payload['accept_requested'])) {
                $p->error('Lời mời đang được xử lý hoặc đã được chấp nhận.');
            }
            $p->queue($actor, $profile, 'friend.accept', ['request_id' => $payload['request_id']], null, null, ['friend_record_id' => $record->id]);
            $payload['accept_requested'] = true;
            DB::table('agent_records')->where('id', $id)->update(['data' => json_encode($payload)]);
        } elseif ($section === 'contact-lists') {
            if ($action === 'delete') {
                $item = $p->own(WorkspaceItem::class, $id, $actor);
                abort_unless($item->kind === 'contact', 404);
                $p->version($item, $data);
                $item->delete();
            } elseif ($action === 'import') {
                $validate(['csv_text' => 'nullable|string|max:1000000', 'csv_file' => 'nullable|file|max:2048', 'list_name' => 'required|string|max:100']);
                $text = $request->file('csv_file') ? file_get_contents($request->file('csv_file')->getRealPath()) : ($data['csv_text'] ?? '');
                $lines = preg_split('/\R/u', trim(preg_replace('/^\xEF\xBB\xBF/', '', $text)), -1, PREG_SPLIT_NO_EMPTY);
                if (! count($lines) || count($lines) > 1001) {
                    $p->error('CSV cần có 1–1000 dòng dữ liệu.');
                }
                $count = 0;
                foreach ($lines as $index => $line) {
                    $values = str_getcsv($line);
                    if ($index === 0 && in_array(strtolower(trim($values[0] ?? '')), ['name', 'ten', 'tên'], true)) {
                        continue;
                    }
                    $row = array_combine(['name', 'phone', 'zalo_user_id', 'labels', 'birthday'], array_pad(array_slice($values, 0, 5), 5, null));
                    $row['list_name'] = $data['list_name'];
                    $this->saveContact($actor, $row);
                    $count++;
                }
                $result['message'] = 'Đã nhập '.$count.' liên hệ.';
            } elseif (in_array($action, ['create', 'update'], true)) {
                $item = $action === 'update' ? $p->own(WorkspaceItem::class, $id, $actor) : null;
                if ($item) {
                    abort_unless($item->kind === 'contact', 404);
                    $p->version($item, $data);
                }
                $result['record'] = $this->saveContact($actor, $data, $item)->toArray();
            } else {
                abort(404);
            }
        } elseif (in_array($section, ['scan-members', 'scan-links'], true)) {
            abort_unless($action === 'create', 404);
            $validate(['profile_id' => 'required|uuid', 'list_name' => 'required|string|max:100']);
            $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
            if ($section === 'scan-members') {
                $validate(['group_id' => 'required|string|max:191']);
                $command = $p->queue($actor, $profile, 'group.members.sync', ['group_id' => $data['group_id'], 'mode' => 'full'], null, null, ['import_list' => $data['list_name']]);
            } else {
                $validate(['invite_url' => 'required|url:https|max:2048']);
                $command = $p->queue($actor, $profile, 'group.inspect_link', ['invite_url' => $data['invite_url']], null, null, ['next' => 'group.members.sync', 'import_list' => $data['list_name']]);
            }
            $result['record'] = $command->toArray();
        } elseif ($section === 'opt-outs') {
            if ($action === 'create') {
                $validate(['recipient' => 'required|string|max:191', 'reason' => 'nullable|string|max:1000']);
                $item = WorkspaceItem::firstOrCreate(['user_id' => $actor->id, 'kind' => 'opt_out', 'title' => $data['recipient']], ['data' => ['reason' => $data['reason'] ?? '']]);
                $result['record'] = $item->toArray();
            } elseif ($action === 'delete') {
                $item = $p->own(WorkspaceItem::class, $id, $actor);
                abort_unless($item->kind === 'opt_out', 404);
                $p->version($item, $data);
                $item->delete();
            } else {
                abort(404);
            }
        } elseif (str_starts_with($section, 'group-')) {
            abort_unless($action === 'create', 404);
            $validate(['profile_id' => 'required|uuid']);
            $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
            if (in_array($section, ['group-join', 'group-check'], true)) {
                $validate(['invite_url' => 'required|url:https|max:2048']);
                $command = $p->queue($actor, $profile, $section === 'group-join' ? 'group.join' : 'group.inspect_link', ['invite_url' => $data['invite_url']]);
                $result['record'] = $command->toArray();
            } else {
                $validate(['ids' => 'required|string|max:20000', 'group_id' => 'required_if:section,group-invite|nullable|string|max:191']);
                if ($section === 'group-invite' && empty($data['group_id'])) {
                    $p->error('Cần nhập ID nhóm.');
                }
                $ids = array_unique(preg_split('/[\s,;]+/', $data['ids'], -1, PREG_SPLIT_NO_EMPTY));
                if (count($ids) > 200) {
                    $p->error('Tối đa 200 ID mỗi lượt.');
                }
                foreach ($ids as $providerId) {
                    if (strlen($providerId) > 191) {
                        $p->error('ID quá dài.');
                    }
                    $payload = $section === 'group-invite' ? ['group_id' => $data['group_id'], 'user_id' => $providerId] : ['group_id' => $providerId];
                    $p->queue($actor, $profile, $section === 'group-invite' ? 'group.invite' : 'group.leave', $payload);
                }
            }
        } elseif (in_array($section, ['automation-labels', 'drip', 'rotation', 'message-birthdays-rules'], true)) {
            if (in_array($action, ['pause', 'activate', 'delete'], true)) {
                $item = $p->own(WorkspaceItem::class, $id, $actor);
                abort_unless($item->kind === 'automation', 404);
                $p->version($item, $data);
                if ($action === 'delete') {
                    $item->delete();
                } else {
                    $payload = $item->data;
                    $payload['last_error'] = null;
                    if ($action === 'activate' && $item->status !== 'active') {
                        $payload['next_run_at'] = now()->toISOString();
                    }
                    $item->update(['status' => $action === 'pause' ? 'paused' : 'active', 'data' => $payload, 'version' => $item->version + 1]);
                }
            } else {
                abort_unless($action === 'create', 404);
                $validate(['name' => 'required|string|max:255', 'profile_id' => 'required|uuid', 'profile_ids' => 'nullable|array|max:100', 'profile_ids.*' => 'uuid|distinct', 'text' => 'required_without:steps|nullable|string|max:2000', 'steps' => 'required_if:mode,drip|nullable|string|max:65000', 'recipient_ids' => 'nullable|string|max:50000', 'source' => ['required', Rule::in(['message-friends', 'message-lists', 'message-labels', 'message-birthdays'])], 'source_value' => 'nullable|string|max:255', 'scheduled_at' => 'nullable|date', 'interval_minutes' => 'required|integer|min:5|max:43200', 'label' => 'required_if:mode,incoming|nullable|string|max:100']);
                $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
                $mode = ['automation-labels' => 'incoming', 'drip' => 'drip', 'rotation' => 'rotation', 'message-birthdays-rules' => 'birthdays'][$section];
                if ($mode === 'incoming' && empty($data['label'])) {
                    $p->error('Cần nhập nhãn kích hoạt.');
                }
                $steps = $mode === 'drip' ? $p->steps($data['steps'] ?? '') : [['delay_minutes' => 0, 'text' => $data['text']]];
                foreach ($data['profile_ids'] ?? [] as $profileId) {
                    $p->own(AgentProfile::class, $profileId, $actor);
                }
                $item = WorkspaceItem::create(['user_id' => $actor->id, 'kind' => 'automation', 'title' => $data['name'], 'data' => [
                    'mode' => $mode, 'profile_id' => $profile->id, 'source' => $mode === 'birthdays' ? 'message-birthdays' : $data['source'],
                    'source_value' => $data['source_value'] ?? '', 'recipient_ids' => $data['recipient_ids'] ?? '',
                    'steps' => $steps, 'interval_minutes' => (int) $data['interval_minutes'], 'label' => $data['label'] ?? '',
                    'profile_ids' => ! empty($data['profile_ids']) ? $data['profile_ids'] : [$profile->id],
                    'next_run_at' => empty($data['scheduled_at']) ? now()->toISOString() : Carbon::parse($data['scheduled_at'], config('app.timezone'))->utc()->toISOString(),
                ]]);
                $result['record'] = $item->toArray();
            }
        } elseif (in_array($section, ['command', 'reports'], true)) {
            abort_unless($action === 'cancel', 404);
            $command = AgentCommand::findOrFail($id);
            $p->own(AgentProfile::class, $command->profile_id, $actor);
            $p->version($command, $data);
            $p->cancelCommand($command);
            $result['record'] = $command->toArray();
        } elseif ($section === 'inbox') {
            if ($action === 'read-conversation') {
                $validate(['profile_id' => 'required|uuid', 'conversation_type' => ['required', Rule::in(['user', 'group'])], 'conversation_id' => 'required|string|max:191']);
                $p->own(AgentProfile::class, $data['profile_id'], $actor);
                DB::table('agent_records')->where('profile_id', $data['profile_id'])->where('kind', 'messages')
                    ->where('conversation_type', $data['conversation_type'])->where('conversation_id', $data['conversation_id'])->whereNull('read_at')
                    ->update(['read_at' => now()]);
                $result['message'] = 'Đã đánh dấu cuộc hội thoại là đã đọc trên web.';
            } elseif ($action === 'read') {
                $record = DB::table('agent_records')->where('id', $id)->where('kind', 'messages')->first();
                abort_unless($record, 404);
                $p->own(AgentProfile::class, $record->profile_id, $actor);
                $payload = json_decode($record->data, true);
                $payload['read_at'] = now()->toISOString();
                DB::table('agent_records')->where('id', $id)->update(['data' => json_encode($payload), 'read_at' => now()]);
            } elseif ($action === 'reply') {
                $validate(['profile_id' => 'required|uuid', 'conversation_id' => 'required|string|max:191', 'conversation_type' => ['required', Rule::in(['user', 'group'])], 'text' => 'required|string|max:2000']);
                $profile = $p->own(AgentProfile::class, $data['profile_id'], $actor);
                $result['record'] = $p->queue($actor, $profile, 'message.send', ['recipient' => ['type' => $data['conversation_type'], 'id' => $data['conversation_id']], 'content' => ['type' => 'text', 'text' => $data['text']]])->toArray();
            } else {
                abort(404);
            }
        } elseif (in_array($section, ['billing', 'payments'], true)) {
            if ($action === 'create' && ! $admin) {
                $validate(['plan_id' => 'required|uuid', 'billing_period' => ['required', Rule::in(['monthly', 'yearly'])]]);
                $result['record'] = $p->createOrder($actor, Plan::findOrFail($data['plan_id']), $data['billing_period'])->toArray();
            } else {
                $order = $p->own(Order::class, $id, $actor, $admin);
                $p->version($order, $data);
                if ($action === 'cancel') {
                    if ($order->status !== 'pending') {
                        $p->error('Chỉ hủy đơn đang chờ thanh toán.');
                    }
                    $order->update(['status' => 'cancelled', 'version' => $order->version + 1]);
                } elseif ($action === 'confirm' && $admin) {
                    $validate(['reference' => 'required|string|max:191', 'amount_vnd' => 'required|integer|min:1']);
                    $p->confirmOrder($actor, $order, $data + ['reason' => $reason]);
                } elseif ($action === 'refund' && $admin) {
                    $validate(['refund_reference' => 'required|string|max:191', 'amount_vnd' => 'required|integer|min:1']);
                    $p->refund($actor, $order, $data + ['reason' => $reason]);
                } else {
                    abort(404);
                }
                $result['record'] = $order->fresh()->toArray();
            }
        } elseif ($section === 'affiliate') {
            $commission = $p->own(AffiliateCommission::class, $id, $actor, $admin);
            $p->version($commission, $data);
            if ($action === 'request' && ! $admin && $commission->status === 'approved') {
                $commission->update(['status' => 'requested', 'version' => $commission->version + 1]);
            } elseif ($action === 'approve' && $admin && $commission->status === 'pending') {
                $commission->update(['status' => 'approved', 'version' => $commission->version + 1]);
            } elseif ($action === 'paid' && $admin && in_array($commission->status, ['approved', 'requested'], true)) {
                $validate(['reference' => 'required|string|max:191']);
                $commission->update(['status' => 'paid', 'payment_reference' => $data['reference'], 'version' => $commission->version + 1]);
            } else {
                $p->error('Trạng thái hoa hồng không cho phép thao tác này.');
            }
        } elseif ($admin) {
            return $this->admin($actor, $section, $action, $request);
        } else {
            abort(404);
        }
        $p->audit($actor, ($admin ? 'admin.' : 'user.').$section.'.'.$action, $id ?: $section, $admin ? $reason : '');

        return $result;
    }

    private function saveContact(User $user, array $data, ?WorkspaceItem $item = null): WorkspaceItem
    {
        $data = array_map(fn ($value) => is_string($value) ? (trim($value) ?: null) : $value, $data);
        $validated = Validator::make($data, ['name' => 'required|string|max:255', 'phone' => 'required_without:zalo_user_id|nullable|regex:/^\+?[0-9]{9,15}$/', 'zalo_user_id' => 'required_without:phone|nullable|string|max:191', 'labels' => 'nullable|string|max:500', 'birthday' => 'nullable|date_format:Y-m-d', 'list_name' => 'required|string|max:100'])->validate();
        $title = $validated['name'];
        unset($validated['name']);
        if (! $item) {
            $item = WorkspaceItem::where('user_id', $user->id)->where('kind', 'contact')->get()->first(fn ($record) => ($record->data['zalo_user_id'] ?? null) && $record->data['zalo_user_id'] === ($validated['zalo_user_id'] ?? null) && $record->data['list_name'] === $validated['list_name']);
        }
        if ($item) {
            $item->update(['title' => $title, 'data' => $validated, 'version' => $item->version + 1]);
        } else {
            $item = WorkspaceItem::create(['user_id' => $user->id, 'kind' => 'contact', 'title' => $title, 'data' => $validated]);
        }

        return $item->fresh();
    }

    private function campaignAction(User $actor, string $id, string $action, array $data): array
    {
        $campaign = $this->platform->own(Campaign::class, $id, $actor);
        $this->platform->version($campaign, $data);
        if ($action === 'cancel') {
            foreach (AgentCommand::where('campaign_id', $id)->whereIn('status', ['queued', 'pending_approval', 'leased', 'running'])->get() as $command) {
                $this->platform->cancelCommand($command);
            }
            $campaign->update(['status' => 'cancelled', 'version' => $campaign->version + 1]);
        } elseif ($action === 'pause' && ! in_array($campaign->status, ['completed', 'cancelled'], true)) {
            $campaign->update(['status' => 'paused', 'version' => $campaign->version + 1]);
        } elseif ($action === 'resume' && $campaign->status === 'paused') {
            $campaign->update(['status' => 'scheduled', 'version' => $campaign->version + 1]);
        } else {
            $this->platform->error('Chiến dịch không ở trạng thái phù hợp.');
        }
        $this->platform->audit($actor, 'campaign.'.$action, $campaign);

        return ['message' => 'Đã cập nhật chiến dịch.', 'record' => $campaign->toArray()];
    }

    private function admin(User $actor, string $section, string $action, Request $request): array
    {
        $p = $this->platform;
        $data = $request->all();
        $id = (string) $request->input('id');
        $reason = $request->input('reason', 'Thao tác từ giao diện quản trị');
        if ($section === 'users') {
            if ($action === 'create') {
                $validated = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:12|max:128', 'role' => ['required', Rule::in(['user', 'admin'])]]);
                $user = User::create(array_intersect_key($validated, array_flip(['name', 'email', 'password'])));
                $user->assignRole(Role::findOrCreate($validated['role'], 'web'));
            } else {
                $user = User::findOrFail($id);
                $p->version($user, $data);
                if ($action === 'update') {
                    $validated = $request->validate(['name' => 'required|string|max:255']);
                    $user->update($validated);
                } elseif ($action === 'password') {
                    $validated = $request->validate(['password' => 'required|string|min:12|max:128|confirmed']);
                    $user->update(['password' => $validated['password']]);
                    $user->tokens()->delete();
                } elseif (in_array($action, ['role', 'suspend', 'activate'], true)) {
                    if ($action === 'role') {
                        $request->validate(['role' => ['required', Rule::in(['admin', 'user'])]]);
                    }
                    if (($action === 'suspend' || ($action === 'role' && $data['role'] !== 'admin')) && ($user->id === $actor->id || ($user->hasRole('admin') && User::role('admin')->where('status', 'active')->count() <= 1))) {
                        $p->error('Không thể tự khóa/hạ quyền mình hoặc loại bỏ quản trị viên hoạt động cuối cùng.');
                    }
                    if ($action === 'role') {
                        $user->syncRoles([Role::findOrCreate($data['role'], 'web')]);
                    } else {
                        $user->forceFill(['status' => $action === 'suspend' ? 'suspended' : 'active'])->save();
                        if ($action === 'suspend') {
                            $user->tokens()->delete();
                            foreach (AgentCommand::whereIn('profile_id', AgentProfile::where('user_id', $user->id)->select('id'))->whereIn('status', ['queued', 'pending_approval', 'leased', 'running'])->get() as $command) {
                                $p->cancelCommand($command);
                            }
                            WorkspaceItem::where('user_id', $user->id)->where('kind', 'automation')->update(['status' => 'paused']);
                        }
                    }
                } else {
                    abort(404);
                }
                $user->increment('version');
            }
            $record = $user;
        } elseif ($section === 'plans') {
            if (in_array($action, ['create', 'update'], true)) {
                $rules = ['name' => 'required|string|max:255', 'monthly_price_vnd' => 'required|integer|min:0|max:1000000000', 'yearly_price_vnd' => 'required|integer|min:0|max:1000000000', 'profiles_max' => 'required|integer|min:0|max:10000', 'messages_per_day' => 'required|integer|min:0|max:1000000'];
                if ($action === 'create') {
                    $rules['code'] = 'required|alpha_dash|max:50|unique:plans,code';
                }
                $validated = $request->validate($rules);
                if ($action === 'create') {
                    $record = Plan::create($validated);
                } else {
                    $record = Plan::findOrFail($id);
                    $p->version($record, $data);
                    if ($record->code === 'free') {
                        $validated['monthly_price_vnd'] = $validated['yearly_price_vnd'] = 0;
                    }
                    $record->update($validated + ['version' => $record->version + 1]);
                }
            } elseif (in_array($action, ['archive', 'activate'], true)) {
                $record = Plan::findOrFail($id);
                $p->version($record, $data);
                if ($record->code === 'free') {
                    $p->error('Gói miễn phí mặc định phải luôn hoạt động.');
                }
                $record->update(['status' => $action === 'archive' ? 'archived' : 'active', 'version' => $record->version + 1]);
            } else {
                abort(404);
            }
        } elseif ($section === 'nodes') {
            if ($action === 'update') {
                $validated = $request->validate(['max_concurrency' => 'required|integer|min:1|max:100', 'enabled' => 'required|boolean']);
                DB::table('agent_runtime')->where('id', 1)->update($validated);
            } elseif (in_array($action, ['enable', 'disable'], true)) {
                DB::table('agent_runtime')->where('id', 1)->update(['enabled' => $action === 'enable']);
            } else {
                abort(404);
            }
            $record = 'agent_default';
        } elseif (in_array($section, ['queue', 'moderation'], true) && in_array($action, ['cancel', 'resolve', 'approve'], true)) {
            $record = AgentCommand::findOrFail($id);
            $p->version($record, $data);
            if ($action === 'cancel') {
                $p->cancelCommand($record);
            } elseif ($action === 'approve' && $record->status === 'pending_approval') {
                $record->update(['status' => 'queued', 'version' => $record->version + 1]);
            } elseif ($action === 'resolve' && $record->status === 'unknown') {
                $request->validate(['resolution' => ['required', Rule::in(['succeeded', 'failed'])], 'evidence_reference' => 'required|string|min:5|max:1000', 'output_json' => 'nullable|json|max:65000']);
                $output = empty($data['output_json']) ? null : json_decode($data['output_json'], true);
                if ($data['resolution'] === 'succeeded' && $record->type === 'contact.resolve' && empty($output['zalo_user_id'])) {
                    $p->error('Cần nhập kết quả JSON chứa zalo_user_id đã đối soát.');
                }
                $record->update(['status' => $data['resolution'], 'finished_at' => now(), 'version' => $record->version + 1, 'result' => ['resolved_by' => $actor->id, 'evidence_reference' => $data['evidence_reference'], 'reason' => $reason, 'output' => $output]]);
                app(PlatformEngine::class)->finished($record);
            } else {
                $p->error('Lệnh không ở trạng thái phù hợp.');
            }
        } elseif ($section === 'settings') {
            abort_unless($action === 'save', 404);
            $validated = $request->validate(['support_email' => 'nullable|email|max:255', 'maintenance_mode' => 'required|boolean', 'bank_name' => 'nullable|string|max:255', 'bank_account' => 'nullable|string|max:100', 'bank_holder' => 'nullable|string|max:255', 'payment_note' => 'nullable|string|max:2000', 'affiliate_percent' => 'required|integer|min:0|max:50', 'moderation_required' => 'required|boolean']);
            $record = WorkspaceItem::where('kind', 'settings')->first();
            if ($record) {
                $p->version($record, $data);
                $record->update(['data' => $validated, 'version' => $record->version + 1]);
            } else {
                $record = WorkspaceItem::create(['kind' => 'settings', 'title' => 'system', 'data' => $validated]);
            }
        } elseif (in_array($section, ['proxies', 'announcements', 'moderation'], true)) {
            $kind = ['proxies' => 'proxy', 'announcements' => 'announcement', 'moderation' => 'blocked_word'][$section];
            if ($action === 'delete') {
                $record = WorkspaceItem::where('kind', $kind)->whereNull('user_id')->findOrFail($id);
                $p->version($record, $data);
                $p->audit($actor, $section.'.delete', $record, $reason);
                $record->delete();

                return ['message' => 'Đã xóa.'];
            }
            abort_unless(in_array($action, ['create', 'update'], true), 404);
            $record = $action === 'update' ? WorkspaceItem::where('kind', $kind)->whereNull('user_id')->findOrFail($id) : null;
            if ($record) {
                $p->version($record, $data);
            }
            $request->validate(['title' => 'required|string|max:255']);
            if ($kind === 'proxy') {
                $validated = $request->validate(['scheme' => ['required', Rule::in(['http', 'socks5'])], 'host' => 'required|string|max:255|regex:/^[a-zA-Z0-9.\-:]+$/', 'port' => 'required|integer|min:1|max:65535', 'username' => 'nullable|string|max:255', 'password' => 'nullable|string|max:255']);
                if ($record && empty($validated['password'])) {
                    $validated['password'] = $record->data['password'] ?? null;
                }
            } elseif ($kind === 'announcement') {
                $validated = $request->validate(['body' => 'required|string|max:10000', 'published_at' => 'required|date', 'expires_at' => 'nullable|date|after:published_at']);
                $validated['published_at'] = Carbon::parse($validated['published_at'], config('app.timezone'))->utc()->toISOString();
                $validated['expires_at'] = empty($validated['expires_at']) ? null : Carbon::parse($validated['expires_at'], config('app.timezone'))->utc()->toISOString();
            } else {
                $validated = [];
            }
            if ($record) {
                $record->update(['title' => $data['title'], 'data' => $validated, 'version' => $record->version + 1]);
            } else {
                $record = WorkspaceItem::create(['kind' => $kind, 'title' => $data['title'], 'data' => $validated]);
            }
        } else {
            abort(404);
        }
        $p->audit($actor, 'admin.'.$section.'.'.$action, $record, $reason);
        $array = is_object($record) ? $record->fresh()->toArray() : ['id' => $record];
        if ($section === 'proxies') {
            unset($array['data']['password']);
        }

        return ['message' => 'Đã cập nhật.', 'record' => $array];
    }
}
