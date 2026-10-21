<x-admin-layout>
    <div class="d-flex align-items-start gap-3 p-4 rounded-3 bg-primary-subtle border border-primary-subtle mb-4"><i class="bi bi-shield-check fs-3 text-primary" aria-hidden="true"></i><div><h2 class="h6 mb-2">Không gian quản trị zaloMind</h2><p class="small text-body-secondary mb-0">Theo dõi tài khoản trong hệ thống. Các chức năng vận hành chi tiết được triển khai ở giai đoạn 9.</p></div></div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat-card label="Tổng tài khoản" :value="$userCount" icon="people" hint="Dữ liệu thực trong hệ thống" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Khách hàng" :value="$customerCount" icon="person" hint="Tài khoản có vai trò user" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Quản trị viên" :value="$adminCount" icon="shield-lock" hint="Tài khoản có vai trò admin" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Hạ tầng node" value="Chưa kết nối" icon="hdd-network" hint="Kích hoạt ở giai đoạn 4" /></div>
    </div>
    <div class="row g-4">
        <div class="col-lg-8"><x-card title="Hạ tầng & vận hành"><x-empty-state title="Sẵn sàng kết nối hệ thống" description="Node, profile và hàng đợi sẽ xuất hiện khi các luồng nghiệp vụ được triển khai." illustration="empty-profiles" action="Xem quản lý node" :href="route('admin.section', 'nodes')" /></x-card></div>
        <div class="col-lg-4"><x-card title="Điều hướng quản trị"><div class="d-grid gap-3">
            <a class="quick-action" href="{{ route('admin.section', 'users') }}"><i class="bi bi-people" aria-hidden="true"></i><span><strong>Người dùng</strong><small>Quản lý tài khoản khách hàng</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            <a class="quick-action" href="{{ route('admin.section', 'settings') }}"><i class="bi bi-sliders" aria-hidden="true"></i><span><strong>Cấu hình hệ thống</strong><small>Thiết lập vận hành</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            <a class="quick-action" href="{{ route('admin.section', 'audit') }}"><i class="bi bi-journal-text" aria-hidden="true"></i><span><strong>Nhật ký kiểm toán</strong><small>Theo dõi hoạt động quản trị</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </div></x-card></div>
    </div>
</x-admin-layout>
