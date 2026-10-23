<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Platform
{
    private array $blockedRecipients = [];

    public function error(string $message): never
    {
        if (request()->is('api/v1/*') && ! request()->is('api/v1/agent/*')) {
            \App\Support\AgentApi::fail('INVALID_STATE', $message);
        }
        throw ValidationException::withMessages(['operation' => $message]);
    }

    public function settings(): array
    {
        return (WorkspaceItem::where('kind', 'settings')->first()?->data ?? []) + [
            'support_email' => '', 'maintenance_mode' => false, 'bank_name' => '',
            'bank_account' => '', 'bank_holder' => '', 'payment_note' => '',
            'affiliate_percent' => 0, 'moderation_required' => false,
        ];
    }

    public function audit(?User $actor, string $action, $resource, string $reason = '', array $changes = []): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_id' => $actor?->id, 'action' => $action,
            'resource_type' => is_object($resource) ? class_basename($resource) : 'system',
            'resource_id' => is_object($resource) ? (string) $resource->id : (string) $resource,
            'reason' => $reason, 'changes' => json_encode($changes), 'created_at' => now(),
        ]);
    }

    public function own(string $model, string $id, User $user, bool $admin = false)
    {
        $record = $model::findOrFail($id);
        abort_unless($admin || (int) $record->user_id === $user->id, 404);

        return $record;
    }

    public function version($model, array $data): void
    {
        if (! isset($data['expected_version']) || (int) $model->version !== (int) $data['expected_version']) {
            if (request()->is('api/v1/*') && ! request()->is('api/v1/agent/*')) {
                \App\Support\AgentApi::fail('VERSION_CONFLICT', 'Dữ liệu đã thay đổi. Tải lại rồi thử lại.');
            }
            $this->error('Dữ liệu đã thay đổi. Tải lại trang rồi thực hiện lại.');
        }
    }

    public function subscription(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
    }

    public function limits(User $user): array
    {
        $subscription = $this->subscription($user);
        $free = Plan::where('code', 'free')->first();

        return $subscription?->limits_snapshot ?? ['profiles_max' => $free?->profiles_max ?? 0, 'messages_per_day' => $free?->messages_per_day ?? 0];
    }

    public function used(User $user, ?Carbon $date = null): int
    {
        $date ??= now();

        return (int) AgentCommand::whereIn('profile_id', AgentProfile::withTrashed()->where('user_id', $user->id)->select('id'))
            ->whereNotIn('status', ['failed', 'cancelled'])->whereBetween(DB::raw('COALESCE(available_at, created_at)'), [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->sum('quota_units');
    }

    public function createProfile(User $user, string $name): AgentProfile
    {
        if (AgentProfile::where('user_id', $user->id)->count() >= $this->limits($user)['profiles_max']) {
            $this->error('Đã hết số tài khoản Zalo trong gói. Vui lòng nâng cấp gói.');
        }
        $profile = AgentProfile::create(['user_id' => $user->id, 'display_name' => $name]);
        $this->audit($user, 'profile.create', $profile);

        return $profile->fresh();
    }

    public function optedOut(User $user, string $recipient): bool
    {
        if (! array_key_exists($user->id, $this->blockedRecipients)) {
            $blocked = array_fill_keys(WorkspaceItem::where('user_id', $user->id)->where('kind', 'opt_out')->where('status', 'active')->pluck('title')->all(), true);
            if ($blocked) {
                $contacts = WorkspaceItem::where('user_id', $user->id)->where('kind', 'contact')->get()->map(fn ($item) => $item->data)->all();
                $synced = DB::table('agent_records')->whereIn('profile_id', AgentProfile::where('user_id', $user->id)->select('id'))->where('kind', 'contacts')->get()->map(fn ($item) => json_decode($item->data, true))->all();
                $identities = array_map(function ($contact) {
                    $aliases = array_filter([$contact['zalo_user_id'] ?? null, $contact['phone'] ?? null]);
                    $phone = $contact['phone'] ?? '';
                    if (preg_match('/^\+?84([1-9][0-9]{8,9})$/D', $phone, $match)) {
                        $aliases[] = '0'.$match[1];
                    } elseif (preg_match('/^0([1-9][0-9]{8,9})$/D', $phone, $match)) {
                        $aliases[] = '+84'.$match[1];
                        $aliases[] = '84'.$match[1];
                    }

                    return array_values($aliases);
                }, array_merge($contacts, $synced));
                do {
                    $before = count($blocked);
                    foreach ($identities as $aliases) {
                        if (array_intersect($aliases, array_keys($blocked))) {
                            foreach ($aliases as $alias) {
                                $blocked[$alias] = true;
                            }
                        }
                    }
                } while (count($blocked) !== $before);
            }
            $this->blockedRecipients[$user->id] = $blocked;
        }

        return isset($this->blockedRecipients[$user->id][$recipient]);
    }

    public function queue(User $user, AgentProfile $profile, string $type, array $payload = [], ?Carbon $at = null, ?Campaign $campaign = null, array $workflow = [], ?int $units = null): AgentCommand
    {
        abort_unless($profile->user_id === $user->id, 404);
        if ($type !== 'profile.logout' && ($user->status !== 'active' || $this->settings()['maintenance_mode'])) {
            $this->error('Tài khoản bị khóa hoặc hệ thống đang bảo trì.');
        }
        if (! in_array($type, \App\Http\Controllers\AgentController::TYPES, true)) {
            $this->error('Loại tác vụ không được hỗ trợ.');
        }
        if (! in_array($type, ['profile.login_qr', 'profile.logout'], true) && $profile->status !== 'connected') {
            $this->error('Cần kết nối tài khoản Zalo trước khi thực hiện.');
        }
        $allowedProfiles = AgentProfile::where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->limit((int) $this->limits($user)['profiles_max'])->pluck('id');
        if ($type !== 'profile.logout' && ! $allowedProfiles->contains($profile->id)) {
            $this->error('Tài khoản Zalo này vượt giới hạn gói hiện tại. Vui lòng nâng cấp hoặc xóa tài khoản không dùng.');
        }
        $at ??= now();
        $units ??= in_array($type, ['message.send', 'contact.resolve'], true) ? 1 : 0;
        if ($units && $this->used($user, $at) + $units > $this->limits($user)['messages_per_day']) {
            $this->error('Hạn mức tin nhắn ngày '.$at->format('d/m/Y').' không đủ.');
        }
        $recipient = $payload['recipient']['id'] ?? $payload['phone'] ?? null;
        if ($recipient && $this->optedOut($user, $recipient)) {
            $this->error('Người nhận '.$recipient.' đã từ chối nhận tin.');
        }
        $text = $payload['content']['text'] ?? $workflow['content']['text'] ?? '';
        foreach (WorkspaceItem::where('kind', 'blocked_word')->whereNull('user_id')->where('status', 'active')->get() as $word) {
            if (mb_stripos($text, $word->title) !== false) {
                $this->error('Nội dung chứa từ khóa bị chặn: '.$word->title);
            }
        }
        $command = AgentCommand::create([
            'profile_id' => $profile->id, 'assignment_version' => $profile->assignment_version,
            'type' => $type, 'payload' => $payload, 'workflow' => $workflow ?: null,
            'available_at' => $at, 'campaign_id' => $campaign?->id, 'quota_units' => $units,
            'status' => $type === 'message.send' && $this->settings()['moderation_required'] ? 'pending_approval' : 'queued',
            'deadline_at' => $type === 'profile.login_qr' ? now()->addMinutes(5) : null,
        ]);
        $this->audit($user, 'command.create', $command, '', ['type' => $type, 'profile_id' => $profile->id]);

        return $command->fresh();
    }

    public function cancelCommand(AgentCommand $command): void
    {
        if (in_array($command->status, ['queued', 'pending_approval'], true)) {
            $command->update(['status' => 'cancelled', 'cancel_requested' => true, 'finished_at' => now(), 'version' => $command->version + 1]);
        } elseif (in_array($command->status, ['leased', 'running'], true)) {
            $command->update(['cancel_requested' => true, 'version' => $command->version + 1]);
        } else {
            $this->error('Lệnh đã kết thúc hoặc cần đối soát, không thể hủy.');
        }
        app(PlatformEngine::class)->finished($command);
    }

    public function targets(User $user, AgentProfile $profile, array $data, string $section): array
    {
        $manual = trim($data['recipient_ids'] ?? '');
        if (isset($data['recipient_selection']) || isset($data['selected_recipients'])) {
            if (! in_array($section, ['message-friends', 'message-groups'], true) || empty($data['selected_recipients'])) {
                $this->error('Hãy chọn ít nhất một người nhận hoặc nhóm.');
            }
            $groups = $section === 'message-groups';
            $ids = array_unique($data['selected_recipients']);
            $records = DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', $groups ? 'groups' : 'contacts')->whereIn('id', $ids)->get();
            if ($records->count() !== count($ids)) {
                $this->error('Danh sách người nhận đã thay đổi hoặc không thuộc tài khoản Zalo đã chọn. Vui lòng chọn lại.');
            }
            $targets = $records->map(function ($record) use ($groups) {
                $item = json_decode($record->data, true);

                return ['type' => $groups ? 'group' : 'user', 'id' => $item[$groups ? 'group_id' : 'zalo_user_id']];
            })->all();
        } elseif ($manual !== '') {
            $ids = preg_split('/[\s,;]+/u', $manual, -1, PREG_SPLIT_NO_EMPTY);
            $type = $section === 'message-groups' ? 'group' : ($section === 'message-phones' ? 'phone' : 'user');
            $targets = array_map(fn ($id) => ['type' => $type, 'id' => $id], array_unique($ids));
        } elseif (in_array($section, ['message-friends', 'message-groups'], true)) {
            $groups = $section === 'message-groups';
            $records = DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', $groups ? 'groups' : 'contacts')->get();
            $targets = $records->map(function ($record) use ($groups) {
                $item = json_decode($record->data, true);

                return ['type' => $groups ? 'group' : 'user', 'id' => $item[$groups ? 'group_id' : 'zalo_user_id']];
            })->all();
        } else {
            $contacts = WorkspaceItem::where('user_id', $user->id)->where('kind', 'contact')->where('status', 'active')->get();
            $targets = [];
            foreach ($contacts as $contact) {
                $item = $contact->data;
                if ($section === 'message-lists' && ($item['list_name'] ?? '') !== ($data['source_value'] ?? '')) {
                    continue;
                }
                if ($section === 'message-labels' && ! in_array($data['source_value'] ?? '', array_map('trim', explode(',', $item['labels'] ?? '')), true)) {
                    continue;
                }
                if ($section === 'message-birthdays' && (! ($item['birthday'] ?? null) || substr($item['birthday'], 5) !== now()->format('m-d'))) {
                    continue;
                }
                $id = $item['zalo_user_id'] ?? null;
                $phone = $item['phone'] ?? null;
                if ($id || $phone) {
                    $targets[] = ['type' => $id ? 'user' : 'phone', 'id' => $id ?: $phone];
                }
            }
        }
        $targets = collect($targets)->unique(fn ($item) => $item['type'].':'.$item['id'])->filter(fn ($target) => ! $this->optedOut($user, $target['id']))->values()->all();
        if (! count($targets) || count($targets) > 1000) {
            $this->error('Cần từ 1 đến 1000 người nhận hợp lệ, không thuộc danh sách từ chối.');
        }
        foreach ($targets as $target) {
            if (mb_strlen($target['id']) > 191 || ($target['type'] === 'phone' && ! preg_match('/^\+?[0-9]{9,15}$/D', $target['id']))) {
                $this->error('ID hoặc số điện thoại người nhận không hợp lệ.');
            }
        }

        return $targets;
    }

    public function campaign(User $user, AgentProfile $profile, array $data, string $section, ?string $dedupe = null, ?array $steps = null): Campaign
    {
        if ($dedupe && $existing = Campaign::where('dedupe_key', $dedupe)->first()) {
            return $existing;
        }
        $targets = $this->targets($user, $profile, $data, $section);
        $start = empty($data['scheduled_at']) ? now() : Carbon::parse($data['scheduled_at'], config('app.timezone'))->setTimezone(config('app.timezone'));
        $campaign = Campaign::create(['user_id' => $user->id, 'profile_id' => $profile->id, 'name' => $data['name'], 'kind' => $section, 'status' => 'scheduled', 'dedupe_key' => $dedupe]);
        $steps ??= [['delay_minutes' => 0, 'text' => $data['text']]];
        foreach ($steps as $step) {
            foreach ($targets as $index => $target) {
                $at = $start->copy()->addMinutes($step['delay_minutes'])->addSeconds($index * (int) ($data['interval_seconds'] ?? 5));
                $content = ['type' => 'text', 'text' => $step['text']];
                if ($target['type'] === 'phone') {
                    $this->queue($user, $profile, 'contact.resolve', ['phone' => $target['id']], $at, $campaign, ['next' => 'message.send', 'content' => $content]);
                } else {
                    $this->queue($user, $profile, 'message.send', ['recipient' => $target, 'content' => $content], $at, $campaign);
                }
            }
        }
        $this->audit($user, 'campaign.create', $campaign, '', ['recipients' => count($targets), 'steps' => count($steps)]);

        return $campaign;
    }

    public function createOrder(User $user, Plan $plan, string $period): Order
    {
        if ($plan->status !== 'active' || $plan->code === 'free') {
            $this->error('Gói này không thể mua mới.');
        }
        $current = $this->subscription($user);
        if ($current && $current->plan_id !== $plan->id) {
            $this->error('Cần kết thúc gói hiện tại trước khi đổi sang gói khác.');
        }
        $amount = $period === 'yearly' ? $plan->yearly_price_vnd : $plan->monthly_price_vnd;
        if ($amount <= 0) {
            $this->error('Chưa cấu hình giá bán cho chu kỳ này.');
        }
        $order = Order::create(['number' => 'ZM-'.now()->format('ymd').'-'.strtoupper(Str::random(8)), 'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_snapshot' => $plan->toArray(), 'billing_period' => $period, 'amount_vnd' => $amount, 'expires_at' => now()->addDay()]);
        $this->audit($user, 'order.create', $order);

        return $order->fresh();
    }

    public function confirmOrder(User $admin, Order $order, array $data): Order
    {
        if ($order->status !== 'pending' || $order->expires_at->lte(now())) {
            $this->error('Đơn không còn chờ thanh toán hoặc đã hết hạn.');
        }
        if ((int) $data['amount_vnd'] !== $order->amount_vnd || ($data['currency'] ?? 'VND') !== 'VND') {
            $this->error('Số tiền hoặc loại tiền không khớp đơn hàng.');
        }
        if (Order::where('payment_reference', $data['reference'])->exists()) {
            $this->error('Mã giao dịch đã được dùng cho một đơn khác.');
        }
        $owner = User::findOrFail($order->user_id);
        $active = $this->subscription($owner);
        if ($active && $active->plan_id !== $order->plan_id) {
            $this->error('Người dùng đang có gói khác còn hiệu lực.');
        }
        $last = Subscription::where('user_id', $owner->id)->where('status', 'active')->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
        if ($last && $last->plan_id !== $order->plan_id) {
            $this->error('Đang có lịch kích hoạt gói khác.');
        }
        $start = $last?->ends_at->copy() ?? now();
        $end = $order->billing_period === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $order->plan_id, 'order_id' => $order->id, 'limits_snapshot' => ['profiles_max' => $order->plan_snapshot['profiles_max'], 'messages_per_day' => $order->plan_snapshot['messages_per_day']], 'starts_at' => $start, 'ends_at' => $end]);
        $order->update(['status' => 'paid', 'payment_reference' => $data['reference'], 'paid_at' => now(), 'version' => $order->version + 1]);
        $percent = (int) $this->settings()['affiliate_percent'];
        if ($owner->referred_by && $percent > 0) {
            AffiliateCommission::create(['user_id' => $owner->referred_by, 'order_id' => $order->id, 'amount_vnd' => intdiv($order->amount_vnd * $percent, 100)]);
        }
        $this->audit($admin, 'order.confirm', $order, $data['reason'], ['reference' => $data['reference'], 'amount_vnd' => $data['amount_vnd']]);

        return $order;
    }

    public function refund(User $admin, Order $order, array $data): void
    {
        if ($order->status !== 'paid' || (int) $data['amount_vnd'] !== $order->amount_vnd) {
            $this->error('Chỉ ghi nhận hoàn toàn bộ tiền cho đơn đã thanh toán.');
        }
        $subscription = Subscription::where('order_id', $order->id)->firstOrFail();
        if ($subscription->starts_at->lt(now()) || Subscription::where('user_id', $order->user_id)->where('starts_at', '>=', $subscription->ends_at)->where('status', 'active')->exists()) {
            $this->error('Gói đã bắt đầu hoặc có kỳ gia hạn tiếp. Cần đối soát quyền lợi trước khi hoàn tiền.');
        }
        $commission = AffiliateCommission::where('order_id', $order->id)->first();
        if ($commission?->status === 'paid') {
            $this->error('Hoa hồng đã chi trả; cần đối soát trước khi hoàn tiền.');
        }
        if (Order::where('refund_reference', $data['refund_reference'])->exists()) {
            $this->error('Mã hoàn tiền đã được dùng.');
        }
        $subscription->update(['status' => 'cancelled']);
        $commission?->update(['status' => 'cancelled', 'version' => $commission->version + 1]);
        $order->update(['status' => 'refunded', 'refund_reference' => $data['refund_reference'], 'version' => $order->version + 1]);
        $this->audit($admin, 'order.refund', $order, $data['reason'], ['refund_reference' => $data['refund_reference']]);
    }

    public function steps(string $text): array
    {
        $steps = [];
        foreach (preg_split('/\R/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2 || ! ctype_digit(trim($parts[0])) || (int) $parts[0] > 43200 || trim($parts[1]) === '' || mb_strlen($parts[1]) > 2000) {
                $this->error('Mỗi bước dùng định dạng số_phút|nội_dung; tối đa 30 ngày và 2000 ký tự.');
            }
            $steps[] = ['delay_minutes' => (int) $parts[0], 'text' => trim($parts[1])];
        }
        if (! count($steps) || count($steps) > 30) {
            $this->error('Kịch bản cần từ 1 đến 30 bước.');
        }

        return $steps;
    }
}
