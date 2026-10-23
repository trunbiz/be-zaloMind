<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('workspace.home', $this->data(false));
    }

    public function admin(): View
    {
        return view('workspace.home', $this->data(true));
    }

    private function data(bool $admin): array
    {
        $user = auth()->user();
        $platform = app(\App\Services\Platform::class);
        $profiles = \App\Models\AgentProfile::when(! $admin, fn ($q) => $q->where('user_id', $user->id));
        $commands = \App\Models\AgentCommand::whereIn('profile_id', $profiles->select('id'));
        $stats = $admin ? ['Người dùng' => User::count(), 'Tài khoản Zalo kết nối' => (clone $profiles)->where('status', 'connected')->count(), 'Đơn chờ thanh toán' => \App\Models\Order::where('status', 'pending')->count(), 'Doanh thu đã xác nhận (VND)' => number_format(\App\Models\Order::where('status', 'paid')->sum('amount_vnd'))] : ['Tài khoản Zalo' => (clone $profiles)->count().' / '.$platform->limits($user)['profiles_max'], 'Tin nhắn hôm nay' => $platform->used($user).' / '.$platform->limits($user)['messages_per_day'], 'Lệnh đang chờ' => (clone $commands)->whereIn('status', ['queued', 'pending_approval'])->count(), 'Cần đối soát' => (clone $commands)->where('status', 'unknown')->count()];
        $announcements = \App\Models\WorkspaceItem::where('kind', 'announcement')->where('status', 'active')->latest()->get()->filter(fn ($item) => now()->gte($item->data['published_at']) && (empty($item->data['expires_at']) || now()->lt($item->data['expires_at'])));
        $runtime = \Illuminate\Support\Facades\DB::table('agent_runtime')->where('id', 1)->first();

        return ['isAdmin' => $admin, 'stats' => $stats, 'announcements' => $announcements, 'recent' => $commands->latest()->limit(10)->get(), 'agentOnline' => $runtime->enabled && $runtime->last_heartbeat_at && now()->diffInSeconds($runtime->last_heartbeat_at) <= 90];
    }
}
