<?php

namespace App\Services;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WorkspaceItem;
use Illuminate\Support\Facades\DB;

class ProfileLifecycle
{
    public const CONTROLS = ['profile.login_qr', 'profile.logout'];

    public function __construct(private Platform $platform) {}

    // Callers hold agent_runtime's row lock, shared with claim/result and the scheduler.
    public function request(User $owner, AgentProfile $profile, string $action, ?User $actor = null): array
    {
        abort_unless((int) $profile->user_id === $owner->id, 404);
        abort_unless(in_array($action, ['login', 'logout', 'delete'], true), 404);
        $actor ??= $owner;
        $closing = $action !== 'login';
        app(PlatformEngine::class)->expireCommands();

        if (! $closing && $profile->status === 'connected' && $profile->connection_intent !== 'logout') {
            return ['message' => 'Tài khoản Zalo đã kết nối. Bạn có thể sử dụng ngay.', 'record' => $profile->toArray()];
        }
        $type = $closing ? 'profile.logout' : 'profile.login_qr';
        $current = $profile->control_command_id ? AgentCommand::find($profile->control_command_id) : null;
        // Also adopt an outstanding command created before lifecycle support was installed.
        $current ??= AgentCommand::where('profile_id', $profile->id)->where('type', $type)
            ->whereIn('status', ['queued', 'leased', 'running'])->latest()->orderByDesc('id')->first();
        $reuse = $current && $current->type === $type && in_array($current->status, ['queued', 'leased', 'running'], true)
            && ! $current->cancel_requested && (! $current->deadline_at || $current->deadline_at->gt(now()))
            && (! $profile->qr || now()->lt($profile->qr['expires_at']));
        $mayHaveSession = $profile->zalo_user_id || in_array($profile->status, ['connected', 'awaiting_qr', 'disconnecting'], true)
            || AgentCommand::where('profile_id', $profile->id)->where('attempt', '>', 0)->exists();

        $this->stopWork($profile, $closing, $reuse ? $current->id : null);
        if ($closing && ! $mayHaveSession && ! AgentCommand::where('profile_id', $profile->id)->whereIn('status', ['leased', 'running'])->exists()) {
            $profile->update(['status' => 'disconnected', 'qr' => null, 'connection_intent' => 'logout', 'control_command_id' => null, 'version' => $profile->version + 1]);
            $command = null;
        } else {
            $command = $reuse ? $current : $this->platform->queue($owner, $profile, $type);
            $profile->update([
                'connection_intent' => $closing ? 'logout' : 'login', 'control_command_id' => $command->id,
                'status' => $closing ? 'disconnecting' : ($reuse && $profile->qr ? 'awaiting_qr' : 'connecting'),
                'qr' => $reuse && ! $closing ? $profile->qr : null, 'version' => $profile->version + 1,
            ]);
        }
        $this->platform->audit($actor, 'profile.'.$action, $profile, '', ['control_command_id' => $command?->id]);
        if ($action === 'delete') {
            // Remove from the user's list immediately; keep evidence and a logout tombstone.
            $profile->delete();

            return ['message' => $command ? 'Đã xóa tài khoản khỏi danh sách và hủy các lệnh đang chờ. Agent sẽ đăng xuất khi nhận được yêu cầu.' : 'Đã xóa tài khoản khỏi danh sách.', 'pending' => (bool) $command, 'record' => ['id' => $profile->id, 'deleted' => true, 'logout_command_id' => $command?->id]];
        }
        $message = $closing ? ($command ? 'Đã hủy các lệnh chưa chạy và yêu cầu ngắt kết nối. Bạn không cần hủy từng lệnh.' : 'Đã ngắt kết nối.')
            : ($reuse ? 'Đang sử dụng yêu cầu đăng nhập hiện tại. Mã QR sẽ tự xuất hiện tại tài khoản này.' : 'Đang lấy mã QR. Nếu agent chưa kết nối, yêu cầu sẽ được xử lý khi agent hoạt động.');

        return ['message' => $message, 'pending' => (bool) $command, 'record' => $command?->toArray() ?? $profile->toArray()];
    }

    private function stopWork(AgentProfile $profile, bool $closing, ?string $preserve): void
    {
        $query = AgentCommand::where('profile_id', $profile->id)->whereIn('status', ['queued', 'pending_approval', 'leased', 'running']);
        if (! $closing) {
            $query->whereIn('type', self::CONTROLS);
        }
        if ($preserve) {
            $query->where('id', '!=', $preserve);
        }
        foreach ($query->get() as $command) {
            $this->platform->cancelCommand($command);
        }
        if ($closing) {
            Campaign::where('profile_id', $profile->id)->whereIn('status', ['scheduled', 'running', 'paused'])->update(['status' => 'cancelled', 'version' => DB::raw('version + 1')]);
            foreach (WorkspaceItem::where('kind', 'automation')->where('user_id', $profile->user_id)->where('status', 'active')->get() as $rule) {
                if (in_array($profile->id, $rule->data['profile_ids'] ?? [$rule->data['profile_id']], true)) {
                    $rule->update(['status' => 'paused', 'version' => $rule->version + 1]);
                }
            }
        }
    }

    public function isCurrent(AgentCommand $command, AgentProfile $profile): bool
    {
        return ! $profile->control_command_id || $profile->control_command_id === $command->id;
    }

    public function hint(AgentProfile $profile): string
    {
        $command = $profile->control_command_id ? AgentCommand::find($profile->control_command_id) : null;
        if ($command && in_array($command->status, ['failed', 'unknown', 'cancelled'], true)) {
            return $profile->connection_intent === 'logout' ? 'Chưa xác nhận được đăng xuất từ agent. Bấm Ngắt kết nối để thử lại; bạn vẫn có thể xóa khỏi danh sách.' : 'Yêu cầu QR trước chưa hoàn tất hoặc đã hết hạn. Bấm Lấy mã QR để lấy lại.';
        }
        if ($profile->status === 'connected') {
            return 'Đã kết nối. Bạn có thể đồng bộ bạn bè, nhóm hoặc tạo chiến dịch.';
        }
        if ($profile->connection_intent === 'logout') {
            return $profile->status === 'disconnected' ? 'Đã ngắt kết nối.' : 'Đang ngắt kết nối. Các lệnh chưa chạy đã được hủy tự động; agent sẽ dừng lệnh đang chạy rồi đăng xuất.';
        }
        if ($profile->qr && now()->lt($profile->qr['expires_at'])) {
            return 'Mở Zalo trên điện thoại để quét mã. Mã QR sẽ tự cập nhật tại đây.';
        }

        return $command && in_array($command->status, ['queued', 'leased', 'running'], true) ? 'Đang lấy mã QR. Bạn không cần bấm lại; mã sẽ xuất hiện khi agent trả về.' : 'Bấm Lấy mã QR để kết nối tài khoản Zalo.';
    }
}
