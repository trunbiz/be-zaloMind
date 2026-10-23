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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceScreens
{
    public const STATUS = ['active' => 'Hoạt động', 'archived' => 'Ngừng cung cấp', 'suspended' => 'Đã khóa', 'connected' => 'Đã kết nối', 'disconnected' => 'Chưa kết nối', 'connecting' => 'Đang kết nối', 'awaiting_qr' => 'Chờ quét QR', 'expired' => 'Hết hạn', 'error' => 'Cần xử lý lỗi', 'queued' => 'Chờ agent', 'pending_approval' => 'Chờ duyệt', 'leased' => 'Agent đã nhận', 'running' => 'Đang xử lý', 'scheduled' => 'Đã lên lịch', 'paused' => 'Tạm dừng', 'completed' => 'Hoàn tất', 'succeeded' => 'Thành công', 'failed' => 'Thất bại', 'cancelled' => 'Đã hủy', 'unknown' => 'Cần đối soát', 'pending' => 'Chờ xác nhận', 'paid' => 'Đã thanh toán', 'refunded' => 'Đã hoàn tiền', 'approved' => 'Đã duyệt', 'requested' => 'Đề nghị chi trả'];

    public function __construct(private Platform $platform) {}

    public static function status(?string $value): string
    {
        if ($value === 'disconnecting') {
            return 'Đang ngắt kết nối';
        }

        return self::STATUS[$value ?? ''] ?? ($value ?: '—');
    }

    public function field(string $name, string $label, string $type = 'text', bool $required = true, array $options = [], mixed $value = null, string $help = ''): array
    {
        return compact('name', 'label', 'type', 'required', 'options', 'value', 'help');
    }

    public function form(string $label, string $action, array $fields = [], $record = null, string $style = 'primary', ?string $section = null): array
    {
        return ['label' => $label, 'action' => $action, 'fields' => $fields, 'id' => $record?->id, 'version' => $record?->version, 'style' => $style, 'section' => $section];
    }

    public function screen(User $user, string $section, bool $admin, Request $request): array
    {
        $f = fn (...$args) => $this->field(...$args);
        $form = fn (...$args) => $this->form(...$args);
        $screen = ['intro' => '', 'forms' => [], 'tables' => [], 'stats' => [], 'profiles' => [], 'notes' => [], 'announcements' => []];
        $profileQuery = AgentProfile::query()->when(! $admin, fn ($q) => $q->where('user_id', $user->id));
        $profiles = (clone $profileQuery)->orderBy('display_name')->get();
        $options = $profiles->mapWithKeys(fn ($profile) => [$profile->id => $profile->display_name.' · '.self::status($profile->status)])->all();
        $profileField = $f('profile_id', 'Tài khoản Zalo', 'select', true, $options);
        $commands = AgentCommand::whereIn('profile_id', AgentProfile::withTrashed()->when(! $admin, fn ($q) => $q->where('user_id', $user->id))->select('id'));
        $itemQuery = fn ($kind) => WorkspaceItem::where('kind', $kind)->when(! $admin, fn ($q) => $q->where('user_id', $user->id))->when($admin, fn ($q) => $q->whereNull('user_id'));
        if ($section === 'profiles') {
            $screen['intro'] = $admin ? 'Giám sát kết nối, xử lý đăng nhập và cấu hình proxy cho tài khoản Zalo.' : 'Thêm tài khoản, yêu cầu mã QR và quét bằng Zalo trên điện thoại. Kết nối được xác nhận trực tiếp từ agent.';
            if (! $admin) {
                $screen['forms'][] = $form('Thêm tài khoản Zalo', 'create', [$f('display_name', 'Tên gợi nhớ')]);
                $limits = $this->platform->limits($user);
                $screen['stats'] = ['Tài khoản đang dùng' => count($profiles).' / '.$limits['profiles_max'], 'Tin nhắn hôm nay' => $this->platform->used($user).' / '.$limits['messages_per_day']];
            }
            $proxyOptions = ['' => 'Không dùng proxy'] + WorkspaceItem::where('kind', 'proxy')->pluck('title', 'id')->all();
            foreach ($profiles as $profile) {
                $qr = $profile->qr;
                if ($qr && now()->gte($qr['expires_at'])) {
                    $qr = null;
                }
                $actions = [
                    $form('Lấy mã QR', 'login', [], $profile),
                    $form('Đồng bộ bạn bè', 'sync-contacts', [], $profile, 'outline-primary'),
                    $form('Đồng bộ nhóm', 'sync-groups', [], $profile, 'outline-primary'),
                    $form('Đổi tên', 'rename', [$f('display_name', 'Tên gợi nhớ', 'text', true, [], $profile->display_name)], $profile, 'outline-secondary'),
                    $form('Ngắt kết nối', 'logout', [], $profile, 'outline-danger'),
                    $form('Xóa tài khoản', 'delete', [], $profile, 'danger'),
                ];
                if ($admin) {
                    $actions[] = $form('Cấu hình proxy', 'proxy', [$f('proxy_id', 'Proxy', 'select', false, $proxyOptions, $profile->proxy_id)], $profile, 'outline-secondary');
                }
                foreach ($actions as &$action) {
                    if (in_array($action['action'], ['login', 'logout', 'delete'], true)) {
                        $action['direct'] = true;
                    }
                    if ($action['action'] === 'logout') {
                        $action['confirm'] = 'Ngắt kết nối tài khoản này? Hệ thống sẽ tự hủy các lệnh chưa chạy và yêu cầu agent dừng tác vụ đang chạy.';
                    }
                    if ($action['action'] === 'delete') {
                        $action['confirm'] = 'Xóa tài khoản khỏi danh sách? Các lệnh đang chờ sẽ được hủy và agent sẽ nhận yêu cầu đăng xuất.';
                    }
                }
                unset($action);
                $unknown = AgentCommand::where('profile_id', $profile->id)->where('status', 'unknown')->whereNotIn('type', ProfileLifecycle::CONTROLS)->count();
                $screen['profiles'][] = ['profile' => $profile, 'qr' => $qr, 'actions' => $actions, 'hint' => app(ProfileLifecycle::class)->hint($profile), 'unknown' => $unknown];
            }
            $screen['tables'][] = $this->commandTable((clone $commands)->latest()->paginate(20), $admin);
        } elseif (str_starts_with($section, 'message-')) {
            $screen['intro'] = 'Tạo chiến dịch, chọn người nhận và thời điểm bắt đầu. Bạn có thể tạm dừng, tiếp tục hoặc hủy các lệnh chưa gửi.';
            $help = match ($section) {
                'message-friends' => 'Mỗi dòng một ID Zalo. Để trống để lấy toàn bộ bạn bè đã đồng bộ của tài khoản.',
                'message-groups' => 'Mỗi dòng một ID nhóm. Để trống để dùng danh sách nhóm đã đồng bộ.',
                'message-phones' => 'Mỗi dòng một số điện thoại. Agent cần hỗ trợ tìm ID Zalo từ số điện thoại.',
                default => 'Có thể nhập ID Zalo trực tiếp, hoặc để trống để dùng bộ lọc khách hàng bên dưới.',
            };
            $fields = [$profileField, $f('name', 'Tên chiến dịch'), $f('text', 'Nội dung tin nhắn', 'textarea'), $f('recipient_ids', $section === 'message-phones' ? 'Số điện thoại' : 'ID người nhận / nhóm', 'textarea', $section === 'message-phones', [], null, $help)];
            if (in_array($section, ['message-friends', 'message-groups'], true)) {
                $groups = $section === 'message-groups';
                $recipients = DB::table('agent_records')->whereIn('profile_id', $profiles->pluck('id'))->where('kind', $groups ? 'groups' : 'contacts')->get()->map(function ($record) use ($groups) {
                    $item = json_decode($record->data, true);

                    return ['id' => (string) $record->id, 'profile_id' => $record->profile_id, 'name' => $item[$groups ? 'name' : 'display_name'] ?? $record->provider_id];
                })->sortBy('name')->values()->all();
                $fields[3] = $f('selected_recipients', $groups ? 'Chọn nhóm nhận tin' : 'Chọn bạn bè nhận tin', 'recipients', true, $recipients, [], 'Chọn một hoặc nhiều mục. Nếu chưa có danh sách, vào Tài khoản Zalo để đồng bộ '.($groups ? 'nhóm' : 'bạn bè').'.');
            }
            if (in_array($section, ['message-lists', 'message-labels'], true)) {
                $fields[] = $f('source_value', $section === 'message-labels' ? 'Nhãn khách hàng' : 'Tên tệp khách hàng');
            }
            if ($section === 'message-birthdays') {
                $screen['notes'][] = 'Danh sách sinh nhật được lấy theo ngày hôm nay từ ngày sinh đã lưu trong Khách hàng. Có thể tạo quy tắc gửi mỗi ngày.';
            }
            $fields[] = $f('scheduled_at', 'Bắt đầu lúc', 'datetime-local', false, [], null, 'Để trống để bắt đầu ngay khi agent sẵn sàng.');
            $fields[] = $f('interval_seconds', 'Khoảng cách giữa người nhận (giây)', 'number', true, [], 5);
            $screen['forms'][] = $form('Tạo chiến dịch', 'create', $fields);
            if ($section === 'message-birthdays') {
                $screen['forms'][] = $form('Tự động chúc sinh nhật mỗi ngày', 'create-rule', [$profileField, $f('name', 'Tên quy tắc'), $f('text', 'Tin chúc sinh nhật', 'textarea'), $f('source', '', 'hidden', true, [], 'message-birthdays'), $f('interval_minutes', '', 'hidden', true, [], 1440)]);
                $rules = WorkspaceItem::where('user_id', $user->id)->where('kind', 'automation')->get()->filter(fn ($item) => $item->data['mode'] === 'birthdays');
                $screen['tables'][] = ['title' => 'Quy tắc sinh nhật hằng ngày', 'headers' => ['Tên', 'Trạng thái', 'Lần chạy tiếp', 'Lỗi gần nhất'], 'rows' => $rules->map(fn ($item) => ['cells' => [$item->title, self::status($item->status), $item->data['next_run_at'], $item->data['last_error'] ?? '—'], 'actions' => [$form($item->status === 'active' ? 'Tạm dừng' : 'Kích hoạt', $item->status === 'active' ? 'rule-pause' : 'rule-activate', [], $item, 'outline-primary'), $form('Xóa quy tắc', 'rule-delete', [], $item, 'outline-danger')]])->all()];
            }
            $campaigns = Campaign::where('user_id', $user->id)->where('kind', $section)->latest()->paginate(25);
            $rows = $campaigns->map(function ($campaign) use ($form) {
                $counts = AgentCommand::where('campaign_id', $campaign->id)->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');
                $actions = [];
                if (! in_array($campaign->status, ['cancelled', 'completed'], true)) {
                    $actions[] = $form($campaign->status === 'paused' ? 'Tiếp tục' : 'Tạm dừng', $campaign->status === 'paused' ? 'resume' : 'pause', [], $campaign, 'outline-primary');
                    $actions[] = $form('Hủy chiến dịch', 'cancel', [], $campaign, 'outline-danger');
                }

                return ['cells' => [$campaign->name, self::status($campaign->status), 'Thành công: '.($counts['succeeded'] ?? 0).' · Lỗi: '.($counts['failed'] ?? 0).' · Cần đối soát: '.($counts['unknown'] ?? 0), $campaign->created_at->format('d/m/Y H:i')], 'actions' => $actions];
            })->all();
            $screen['tables'][] = ['title' => 'Chiến dịch của bạn', 'headers' => ['Tên', 'Trạng thái', 'Kết quả tác vụ', 'Tạo lúc'], 'rows' => $rows, 'pagination' => $campaigns];
            $screen['tables'][] = $this->commandTable((clone $commands)->whereIn('type', ['message.send', 'contact.resolve'])->latest()->paginate(20, ['*'], 'commands_page'), false);
        } elseif ($section === 'inbox') {
            $screen['intro'] = 'Hội thoại cá nhân và nhóm được App đồng bộ. Chọn cuộc trò chuyện để xem lịch sử hoặc trả lời.';
            $screen['inbox'] = app(Inbox::class)->screen($profiles, $request);
        } elseif (in_array($section, ['friends', 'friend-requests'], true)) {
            $kind = $section === 'friends' ? 'contacts' : 'friend_requests';
            $query = DB::table('agent_records')->whereIn('profile_id', $profiles->pluck('id'))->where('kind', $kind)->orderByDesc('id');
            if ($request->filled('profile_id')) {
                $query->where('profile_id', $request->input('profile_id'));
            }
            $records = $query->paginate(25);
            if ($section === 'friends') {
                $screen['forms'][] = $form('Đồng bộ danh sách bạn bè', 'sync', [$profileField]);
            }
            $rows = [];
            foreach ($records as $record) {
                $data = json_decode($record->data, true);
                $profileName = $profiles->firstWhere('id', $record->profile_id)?->display_name ?? $record->profile_id;
                $actions = [];
                if ($section === 'friends') {
                    $cells = [$data['display_name'], $data['zalo_user_id'], $data['phone'] ?? '—', $profileName];
                } else {
                    $cells = [$data['display_name'], $data['sender_id'], $data['message'] ?? '', $profileName];
                    if (empty($data['accept_requested'])) {
                        $actions[] = ['label' => 'Chấp nhận lời mời', 'action' => 'accept', 'id' => $record->id, 'fields' => [], 'style' => 'primary'];
                    }
                }
                $rows[] = ['cells' => $cells, 'actions' => $actions];
            }
            $headers = match ($section) {
                'friends' => ['Tên', 'ID Zalo', 'Điện thoại', 'Tài khoản'],
                'friend-requests' => ['Tên', 'ID người gửi', 'Lời nhắn', 'Tài khoản'],
            };
            $screen['tables'][] = ['title' => 'Danh sách', 'headers' => $headers, 'rows' => $rows, 'pagination' => $records];
        } elseif ($section === 'friend-invites') {
            $screen['forms'][] = $form('Gửi lời mời kết bạn', 'create', [$profileField, $f('phones', 'Số điện thoại, mỗi dòng một số', 'textarea'), $f('message', 'Lời nhắn', 'textarea', false)]);
            $screen['tables'][] = $this->commandTable((clone $commands)->where('type', 'friend.request')->latest()->paginate(25), false);
        } elseif ($section === 'contact-lists') {
            $contactFields = [$f('name', 'Tên khách hàng'), $f('phone', 'Điện thoại', 'text', false), $f('zalo_user_id', 'ID Zalo', 'text', false), $f('list_name', 'Tên tệp'), $f('labels', 'Nhãn, ngăn cách bằng dấu phẩy', 'text', false), $f('birthday', 'Ngày sinh', 'date', false)];
            $screen['forms'][] = $form('Thêm khách hàng', 'create', $contactFields);
            $screen['forms'][] = $form('Nhập tệp khách hàng', 'import', [$f('list_name', 'Tên tệp'), $f('csv_file', 'Chọn tệp CSV', 'file', false), $f('csv_text', 'Hoặc dán dữ liệu CSV', 'textarea', false, [], null, 'Cột: name,phone,zalo_user_id,labels,birthday. Ngày sinh YYYY-MM-DD; tối đa 1000 dòng. Có thể để trống phone hoặc zalo_user_id, cần có ít nhất một trường.')]);
            $items = $itemQuery('contact')->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->input('q').'%'))->latest()->paginate(25);
            $rows = $items->map(function ($item) use ($contactFields, $form) {
                $data = $item->data;
                $fields = array_map(function ($field) use ($item, $data) {
                    $field['value'] = $field['name'] === 'name' ? $item->title : ($data[$field['name']] ?? null);

                    return $field;
                }, $contactFields);

                return ['cells' => [$item->title, $data['phone'] ?? '—', $data['zalo_user_id'] ?? '—', $data['list_name'], $data['labels'] ?? '', $data['birthday'] ?? '—'], 'actions' => [$form('Chỉnh sửa', 'update', $fields, $item, 'outline-primary'), $form('Xóa', 'delete', [], $item, 'outline-danger')]];
            })->all();
            $screen['tables'][] = ['title' => 'Khách hàng', 'headers' => ['Tên', 'Điện thoại', 'ID Zalo', 'Tệp', 'Nhãn', 'Ngày sinh'], 'rows' => $rows, 'pagination' => $items];
        } elseif ($section === 'opt-outs') {
            $screen['intro'] = 'Các ID/số điện thoại trong danh sách này được chặn trước khi tạo và trước khi giao lệnh gửi.';
            $screen['forms'][] = $form('Chặn nhận tin', 'create', [$f('recipient', 'ID Zalo hoặc số điện thoại'), $f('reason', 'Lý do', 'text', false)]);
            $items = $itemQuery('opt_out')->latest()->paginate(25);
            $screen['tables'][] = ['title' => 'Từ chối nhận tin', 'headers' => ['ID / số điện thoại', 'Lý do'], 'rows' => $items->map(fn ($item) => ['cells' => [$item->title, $item->data['reason'] ?? ''], 'actions' => [$form('Gỡ chặn', 'delete', [], $item, 'outline-danger')]])->all(), 'pagination' => $items];
        } elseif (in_array($section, ['scan-members', 'scan-links', 'group-invite', 'group-join', 'group-check', 'group-leave'], true)) {
            $fields = [$profileField];
            if (in_array($section, ['scan-links', 'group-join', 'group-check'], true)) {
                $fields[] = $f('invite_url', 'Link mời nhóm', 'url');
            } elseif (in_array($section, ['scan-members', 'group-invite'], true)) {
                $fields[] = $f('group_id', 'ID nhóm');
            }
            if (str_starts_with($section, 'scan-')) {
                $fields[] = $f('list_name', 'Lưu vào tệp khách hàng');
            } elseif ($section === 'group-invite') {
                $fields[] = $f('ids', 'ID Zalo cần mời, mỗi dòng một ID', 'textarea');
            } elseif ($section === 'group-leave') {
                $fields[] = $f('ids', 'ID nhóm cần rời, mỗi dòng một ID', 'textarea');
            }
            $screen['forms'][] = $form('Tạo yêu cầu', 'create', $fields);
            $screen['notes'][] = 'Dữ liệu được thực hiện bởi tài khoản Zalo đã chọn; kết quả và lỗi của agent hiển thị trong lịch sử bên dưới.';
            $screen['tables'][] = $this->commandTable((clone $commands)->where('type', 'like', 'group.%')->latest()->paginate(25), false);
            $groups = DB::table('agent_records')->whereIn('profile_id', $profiles->pluck('id'))->where('kind', 'groups')->orderBy('id')->paginate(25, ['*'], 'groups_page');
            $screen['tables'][] = ['title' => 'Nhóm đã đồng bộ', 'headers' => ['Tên nhóm', 'ID nhóm', 'Thành viên', 'Tài khoản'], 'rows' => $groups->map(function ($row) use ($profiles) {
                $data = json_decode($row->data, true);

                return ['cells' => [$data['name'], $data['group_id'], $data['member_count'], $profiles->firstWhere('id', $row->profile_id)?->display_name], 'actions' => []];
            })->all(), 'pagination' => $groups];
        } elseif (in_array($section, ['automation-labels', 'drip', 'rotation'], true)) {
            $mode = ['automation-labels' => 'incoming', 'drip' => 'drip', 'rotation' => 'rotation'][$section];
            $fields = [$f('name', 'Tên kịch bản'), $profileField, $f('source', 'Nguồn khách hàng', 'select', true, ['message-friends' => 'Bạn bè đã đồng bộ', 'message-lists' => 'Tệp khách hàng', 'message-labels' => 'Nhãn khách hàng', 'message-birthdays' => 'Sinh nhật hôm nay']), $f('source_value', 'Tên tệp / nhãn (nếu chọn)', 'text', false), $f('recipient_ids', 'Hoặc danh sách ID Zalo', 'textarea', false)];
            if ($mode === 'incoming') {
                $fields[] = $f('label', 'Nhãn kích hoạt khi khách nhắn đến');
                $screen['intro'] = 'Tự trả lời khi người có nhãn tương ứng gửi tin đến. Khoảng nghỉ giúp tránh trả lời lặp liên tục.';
            }
            if ($mode === 'drip') {
                $fields[] = $f('steps', 'Các bước chăm sóc', 'textarea', true, [], "0|Xin chào\n1440|Bạn có cần hỗ trợ thêm không?", 'Mỗi dòng: số phút kể từ lúc bắt đầu|nội dung.');
            } else {
                $fields[] = $f('text', 'Nội dung', 'textarea');
            }
            if ($mode === 'rotation') {
                $fields[] = $f('profile_ids', 'Luân phiên các tài khoản (có thể chọn nhiều)', 'multiselect', false, $options);
            }
            $fields[] = $f('interval_minutes', $mode === 'incoming' ? 'Khoảng nghỉ giữa các lần trả lời (phút)' : 'Chu kỳ lặp (phút)', 'number', true, [], 1440);
            $fields[] = $f('scheduled_at', 'Bắt đầu lúc', 'datetime-local', false);
            $screen['forms'][] = $form('Tạo kịch bản', 'create', $fields);
            $items = $itemQuery('automation')->latest()->get()->filter(fn ($item) => $item->data['mode'] === $mode);
            $screen['tables'][] = ['title' => 'Kịch bản của bạn', 'headers' => ['Tên', 'Trạng thái', 'Lần chạy tiếp', 'Lỗi gần nhất'], 'rows' => $items->map(fn ($item) => ['cells' => [$item->title, self::status($item->status), $item->data['mode'] === 'incoming' ? 'Khi có tin nhắn phù hợp' : $item->data['next_run_at'], $item->data['last_error'] ?? '—'], 'actions' => [$form($item->status === 'active' ? 'Tạm dừng' : 'Kích hoạt', $item->status === 'active' ? 'pause' : 'activate', [], $item, 'outline-primary'), $form('Xóa kịch bản', 'delete', [], $item, 'outline-danger')]])->all()];
        } elseif ($section === 'reports' || $section === 'queue') {
            $screen['intro'] = $admin ? 'Theo dõi tác vụ, hủy lệnh chưa hoàn tất và đối soát các kết quả không xác định.' : 'Theo dõi tiến độ và kết quả thực tế từ agent; dữ liệu có thể tải dưới dạng CSV.';
            if ($request->filled('status')) {
                $commands->where('status', $request->input('status'));
            }
            $counts = (clone $commands)->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');
            foreach ($counts as $status => $total) {
                $screen['stats'][self::status($status)] = $total;
            }
            $screen['tables'][] = $this->commandTable((clone $commands)->latest()->paginate(25), $admin);
            if (! $admin) {
                $screen['export_url'] = route('workspace.export');
            }
        } elseif ($section === 'billing' || $section === 'payments') {
            $settings = $this->platform->settings();
            if (! $admin) {
                $limits = $this->platform->limits($user);
                $subscription = $this->platform->subscription($user);
                $screen['stats'] = ['Gói hiện tại' => $subscription ? Plan::find($subscription->plan_id)?->name : 'Miễn phí', 'Hạn dùng' => $subscription?->ends_at->format('d/m/Y H:i') ?? 'Không giới hạn thời gian', 'Số tài khoản tối đa' => $limits['profiles_max'], 'Tin nhắn / ngày' => $limits['messages_per_day']];
                $plans = Plan::where('status', 'active')->where('code', '!=', 'free')->get();
                $screen['forms'][] = $form('Đặt mua / gia hạn gói', 'create', [$f('plan_id', 'Gói dịch vụ', 'select', true, $plans->mapWithKeys(fn ($plan) => [$plan->id => $plan->name.' · '.number_format($plan->monthly_price_vnd).'đ/tháng · '.number_format($plan->yearly_price_vnd).'đ/năm'])->all()), $f('billing_period', 'Chu kỳ', 'select', true, ['monthly' => 'Theo tháng', 'yearly' => 'Theo năm'])]);
                $screen['notes'][] = $settings['bank_account'] ? 'Chuyển khoản: '.$settings['bank_name'].' · '.$settings['bank_account'].' · '.$settings['bank_holder'].'. Nội dung: mã đơn hàng. '.$settings['payment_note'] : 'Quản trị viên chưa cấu hình tài khoản nhận chuyển khoản. Bạn vẫn có thể tạo đơn và liên hệ hỗ trợ để được hướng dẫn.';
            } else {
                $screen['intro'] = 'Xác nhận sau khi đối chiếu giao dịch ngân hàng. Ghi nhận hoàn tiền chỉ dùng khi tiền đã được chuyển lại ngoài hệ thống.';
            }
            $orders = Order::when(! $admin, fn ($q) => $q->where('user_id', $user->id))->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->latest()->paginate(25);
            $rows = $orders->map(function ($order) use ($form, $f, $admin) {
                $actions = [];
                if ($order->status === 'pending') {
                    if ($admin) {
                        $actions[] = $form('Xác nhận đã nhận tiền', 'confirm', [$f('reference', 'Mã giao dịch ngân hàng'), $f('amount_vnd', 'Số tiền đã nhận (VND)', 'number', true, [], $order->amount_vnd), $f('reason', 'Ghi chú đối chiếu')], $order);
                    }
                    $actions[] = $form('Hủy đơn', 'cancel', [], $order, 'outline-danger');
                }
                if ($admin && $order->status === 'paid') {
                    $actions[] = $form('Ghi nhận đã hoàn tiền', 'refund', [$f('refund_reference', 'Mã giao dịch hoàn tiền'), $f('amount_vnd', 'Số tiền (VND)', 'number', true, [], $order->amount_vnd), $f('reason', 'Lý do hoàn tiền')], $order, 'outline-danger');
                }

                return ['cells' => [$order->number, $admin ? User::find($order->user_id)?->email : ($order->plan_snapshot['name'] ?? ''), $order->billing_period === 'yearly' ? 'Năm' : 'Tháng', number_format($order->amount_vnd).'đ', self::status($order->status), $order->payment_reference ?? '—', $order->created_at->format('d/m/Y H:i')], 'actions' => $actions];
            })->all();
            $screen['tables'][] = ['title' => 'Đơn hàng', 'headers' => ['Mã đơn', $admin ? 'Khách hàng' : 'Gói', 'Chu kỳ', 'Số tiền', 'Trạng thái', 'Giao dịch', 'Tạo lúc'], 'rows' => $rows, 'pagination' => $orders];
        } elseif ($section === 'affiliate') {
            if (! $admin) {
                $screen['referral_url'] = url('/register?ref='.$user->referral_code);
                $screen['notes'][] = 'Hoa hồng được ghi nhận khi người được giới thiệu thanh toán đơn hàng. Tỷ lệ hiện tại: '.$this->platform->settings()['affiliate_percent'].'%. Quản trị viên đối soát trước khi chi trả.';
            }
            $items = AffiliateCommission::when(! $admin, fn ($q) => $q->where('user_id', $user->id))->latest()->paginate(25);
            $screen['tables'][] = ['title' => 'Hoa hồng', 'headers' => ['Đơn hàng', 'Người giới thiệu', 'Hoa hồng', 'Trạng thái', 'Giao dịch chi trả'], 'rows' => $items->map(function ($item) use ($form, $f, $admin) {
                $actions = [];
                if ($admin && $item->status === 'pending') {
                    $actions[] = $form('Duyệt hoa hồng', 'approve', [], $item);
                }
                if ($admin && in_array($item->status, ['approved', 'requested'], true)) {
                    $actions[] = $form('Ghi nhận đã chi trả', 'paid', [$f('reference', 'Mã giao dịch chi trả'), $f('reason', 'Ghi chú')], $item);
                }
                if (! $admin && $item->status === 'approved') {
                    $actions[] = $form('Yêu cầu chi trả', 'request', [], $item);
                }

                return ['cells' => [Order::find($item->order_id)?->number, User::find($item->user_id)?->email, number_format($item->amount_vnd).'đ', self::status($item->status), $item->payment_reference ?? '—'], 'actions' => $actions];
            })->all(), 'pagination' => $items];
        } elseif ($admin) {
            $screen = array_replace($screen, $this->adminScreen($section, $request));
        }
        $runtime = DB::table('agent_runtime')->where('id', 1)->first();
        $screen['agent_online'] = $runtime && $runtime->enabled && $runtime->last_heartbeat_at && now()->diffInSeconds($runtime->last_heartbeat_at) <= 90;
        $heartbeat = json_decode($runtime->heartbeat ?? '{}', true);
        $screen['agent_capabilities'] = $heartbeat['capabilities'] ?? [];

        return $screen;
    }

    public function commandTable($commands, bool $admin): array
    {
        $rows = [];
        foreach ($commands as $command) {
            $actions = [];
            if ($admin && in_array($command->status, ['queued', 'pending_approval', 'leased', 'running'], true)) {
                $actions[] = $this->form('Hủy lệnh', 'cancel', [], $command, 'outline-danger', 'queue');
            }
            if ($admin && $command->status === 'pending_approval') {
                $actions[] = $this->form('Duyệt gửi', 'approve', [], $command, 'primary', 'queue');
            }
            if ($admin && $command->status === 'unknown') {
                $actions[] = $this->form('Đối soát kết quả', 'resolve', [$this->field('resolution', 'Kết quả đã kiểm chứng', 'select', true, ['succeeded' => 'Đã thực hiện thành công', 'failed' => 'Chưa thực hiện / thất bại']), $this->field('evidence_reference', 'Bằng chứng / mã đối chiếu'), $this->field('output_json', 'Kết quả bổ sung (JSON)', 'textarea', false, [], null, 'Lệnh tìm số cần {"zalo_user_id":"ID đã kiểm chứng"}.'), $this->field('reason', 'Ghi chú')], $command, 'outline-primary', 'queue');
            }
            if (! $admin && in_array($command->status, ['queued', 'pending_approval', 'leased', 'running'], true)) {
                $actions[] = $this->form('Hủy lệnh', 'cancel', [], $command, 'outline-danger', 'reports');
            }
            $result = $command->result;
            $detail = $result ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $rows[] = ['cells' => [$command->id, AgentProfile::withTrashed()->find($command->profile_id)?->display_name ?? '—', $command->type, self::status($command->status), $command->progress_percent.'%', $command->available_at?->format('d/m/Y H:i') ?? $command->created_at->format('d/m/Y H:i')], 'actions' => $actions, 'detail' => $detail];
        }

        return ['title' => 'Lịch sử tác vụ', 'headers' => ['Mã tác vụ', 'Tài khoản', 'Thao tác', 'Trạng thái', 'Tiến độ', 'Lịch thực hiện'], 'rows' => $rows, 'pagination' => $commands];
    }

    private function adminScreen(string $section, Request $request): array
    {
        $f = fn (...$args) => $this->field(...$args);
        $form = fn (...$args) => $this->form(...$args);
        $screen = ['forms' => [], 'tables' => []];
        if ($section === 'users') {
            $screen['forms'][] = $form('Tạo người dùng', 'create', [$f('name', 'Họ tên'), $f('email', 'Email', 'email'), $f('password', 'Mật khẩu (ít nhất 12 ký tự)', 'password'), $f('role', 'Vai trò', 'select', true, ['user' => 'Người dùng', 'admin' => 'Quản trị viên'])]);
            $users = User::with('roles')->when($request->filled('q'), fn ($q) => $q->where(fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%')->orWhere('email', 'like', '%'.$request->input('q').'%')))->latest()->paginate(25);
            $rows = $users->map(fn ($user) => ['cells' => [$user->name, $user->email, $user->getRoleNames()->implode(', '), self::status($user->status), $user->created_at->format('d/m/Y')], 'actions' => [
                $form('Đổi tên', 'update', [$f('name', 'Họ tên', 'text', true, [], $user->name)], $user, 'outline-primary'),
                $form('Đổi vai trò', 'role', [$f('role', 'Vai trò', 'select', true, ['user' => 'Người dùng', 'admin' => 'Quản trị viên'], $user->hasRole('admin') ? 'admin' : 'user'), $f('reason', 'Lý do')], $user, 'outline-secondary'),
                $form($user->status === 'active' ? 'Khóa tài khoản' : 'Mở khóa', $user->status === 'active' ? 'suspend' : 'activate', [$f('reason', 'Lý do')], $user, 'outline-danger'),
                $form('Đặt lại mật khẩu', 'password', [$f('password', 'Mật khẩu mới', 'password'), $f('password_confirmation', 'Nhập lại mật khẩu', 'password'), $f('reason', 'Lý do')], $user, 'outline-secondary'),
            ]])->all();
            $screen['tables'][] = ['title' => 'Người dùng hệ thống', 'headers' => ['Tên', 'Email', 'Vai trò', 'Trạng thái', 'Ngày tạo'], 'rows' => $rows, 'pagination' => $users];
        } elseif ($section === 'plans') {
            $fields = [$f('name', 'Tên gói'), $f('monthly_price_vnd', 'Giá tháng (VND)', 'number', true, [], 0), $f('yearly_price_vnd', 'Giá năm (VND)', 'number', true, [], 0), $f('profiles_max', 'Số tài khoản Zalo tối đa', 'number', true, [], 1), $f('messages_per_day', 'Tin nhắn mỗi ngày', 'number', true, [], 100)];
            $screen['forms'][] = $form('Tạo gói dịch vụ', 'create', array_merge([$f('code', 'Mã gói (chữ, số, gạch ngang)')], $fields));
            $plans = Plan::orderBy('created_at')->get();
            $screen['tables'][] = ['title' => 'Gói dịch vụ', 'headers' => ['Tên', 'Mã', 'Giá tháng / năm', 'Tài khoản', 'Tin/ngày', 'Trạng thái'], 'rows' => $plans->map(function ($plan) use ($form, $fields) {
                $edit = array_map(function ($field) use ($plan) {
                    $field['value'] = $plan->{$field['name']};

                    return $field;
                }, $fields);
                $actions = [$form('Chỉnh sửa', 'update', $edit, $plan, 'outline-primary')];
                if ($plan->code !== 'free') {
                    $actions[] = $form($plan->status === 'active' ? 'Ngừng bán' : 'Mở bán', $plan->status === 'active' ? 'archive' : 'activate', [], $plan, 'outline-danger');
                }

                return ['cells' => [$plan->name, $plan->code, number_format($plan->monthly_price_vnd).'đ / '.number_format($plan->yearly_price_vnd).'đ', $plan->profiles_max, $plan->messages_per_day, self::status($plan->status)], 'actions' => $actions];
            })->all()];
        } elseif ($section === 'nodes') {
            $runtime = DB::table('agent_runtime')->where('id', 1)->first();
            $heartbeat = json_decode($runtime->heartbeat ?? '{}', true);
            $screen['forms'][] = $form('Cấu hình agent', 'update', [$f('max_concurrency', 'Số tác vụ đồng thời', 'number', true, [], $runtime->max_concurrency), $f('enabled', 'Nhận tác vụ', 'select', true, ['1' => 'Bật', '0' => 'Tắt'], (string) (int) $runtime->enabled)]);
            $screen['stats'] = ['Kết nối gần nhất' => $runtime->last_heartbeat_at ?? 'Chưa có heartbeat', 'Phiên bản APP' => $heartbeat['app_version'] ?? 'Chưa kết nối', 'Sức chứa APP báo' => $heartbeat['capacity'] ?? 0];
            $screen['tables'][] = ['title' => 'Khả năng APP đã công bố', 'headers' => ['Loại tác vụ'], 'rows' => array_map(fn ($type) => ['cells' => [$type], 'actions' => []], $heartbeat['capabilities'] ?? [])];
            $screen['notes'] = ['Dùng agent mặc định và key chung trong .env. Không cần tạo Node ID/token trên giao diện.'];
        } elseif ($section === 'settings') {
            $settings = $this->platform->settings();
            $record = WorkspaceItem::where('kind', 'settings')->first();
            $fields = [$f('support_email', 'Email hỗ trợ', 'email', false), $f('bank_name', 'Ngân hàng nhận chuyển khoản', 'text', false), $f('bank_account', 'Số tài khoản', 'text', false), $f('bank_holder', 'Chủ tài khoản', 'text', false), $f('payment_note', 'Hướng dẫn thanh toán', 'textarea', false), $f('affiliate_percent', 'Hoa hồng giới thiệu (%)', 'number'), $f('maintenance_mode', 'Chế độ bảo trì thao tác Zalo', 'select', true, ['0' => 'Tắt', '1' => 'Bật']), $f('moderation_required', 'Duyệt tin nhắn trước khi gửi', 'select', true, ['0' => 'Không yêu cầu', '1' => 'Yêu cầu admin duyệt'])];
            foreach ($fields as &$field) {
                $value = $settings[$field['name']];
                $field['value'] = is_bool($value) ? (string) (int) $value : $value;
            }
            unset($field);
            $screen['forms'][] = $form('Lưu cấu hình', 'save', $fields, $record);
        } elseif (in_array($section, ['proxies', 'announcements', 'moderation'], true)) {
            $kind = ['proxies' => 'proxy', 'announcements' => 'announcement', 'moderation' => 'blocked_word'][$section];
            $fields = [$f('title', $kind === 'blocked_word' ? 'Từ khóa bị chặn' : 'Tên / tiêu đề')];
            if ($kind === 'proxy') {
                $fields = array_merge($fields, [$f('scheme', 'Giao thức', 'select', true, ['http' => 'HTTP', 'socks5' => 'SOCKS5']), $f('host', 'Địa chỉ máy chủ'), $f('port', 'Cổng', 'number'), $f('username', 'Tên đăng nhập', 'text', false), $f('password', 'Mật khẩu', 'password', false)]);
            } elseif ($kind === 'announcement') {
                $fields = array_merge($fields, [$f('body', 'Nội dung', 'textarea'), $f('published_at', 'Bắt đầu hiển thị', 'datetime-local', true, [], now()->format('Y-m-d\TH:i')), $f('expires_at', 'Hết hạn', 'datetime-local', false)]);
            }
            $screen['forms'][] = $form($kind === 'proxy' ? 'Thêm proxy' : ($kind === 'announcement' ? 'Tạo thông báo' : 'Thêm từ khóa chặn'), 'create', $fields);
            $items = WorkspaceItem::where('kind', $kind)->whereNull('user_id')->latest()->paginate(25);
            $rows = $items->map(function ($item) use ($fields, $form, $kind) {
                $data = $item->data ?? [];
                $edit = array_map(function ($field) use ($item, $data) {
                    $value = $field['name'] === 'title' ? $item->title : ($data[$field['name']] ?? null);
                    $field['value'] = $field['type'] === 'password' ? null : ($field['type'] === 'datetime-local' && $value ? \Carbon\Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i') : $value);

                    return $field;
                }, $fields);
                $cells = match ($kind) {
                    'proxy' => [$item->title, ($data['scheme'] ?? '').'://'.($data['host'] ?? '').':'.($data['port'] ?? ''), $data['username'] ?? '—'],
                    'announcement' => [$item->title, $data['body'], $data['published_at'], $data['expires_at'] ?? 'Không hết hạn'],
                    default => [$item->title],
                };

                return ['cells' => $cells, 'actions' => [$form('Chỉnh sửa', 'update', $edit, $item, 'outline-primary'), $form('Xóa', 'delete', [], $item, 'outline-danger')]];
            })->all();
            $screen['tables'][] = ['title' => $kind === 'proxy' ? 'Proxy' : ($kind === 'announcement' ? 'Thông báo' : 'Từ khóa bị chặn'), 'headers' => match ($kind) {
                'proxy' => ['Tên', 'Máy chủ', 'Đăng nhập'], 'announcement' => ['Tiêu đề', 'Nội dung', 'Bắt đầu', 'Kết thúc'], default => ['Từ khóa']
            }, 'rows' => $rows, 'pagination' => $items];
            if ($kind === 'blocked_word') {
                $screen['tables'][] = $this->commandTable(AgentCommand::where('status', 'pending_approval')->latest()->paginate(25, ['*'], 'commands_page'), true);
            }
        } elseif ($section === 'audit') {
            $items = DB::table('audit_logs')->orderByDesc('created_at')->paginate(50);
            $screen['tables'][] = ['title' => 'Nhật ký kiểm toán', 'headers' => ['Thời gian', 'Người thực hiện', 'Thao tác', 'Đối tượng', 'Lý do'], 'rows' => $items->map(fn ($row) => ['cells' => [$row->created_at, User::find($row->actor_id)?->email ?? 'Hệ thống', $row->action, $row->resource_type.' '.$row->resource_id, $row->reason], 'actions' => [], 'detail' => $row->changes])->all(), 'pagination' => $items];
        }

        return $screen;
    }
}
