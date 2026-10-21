<x-app-layout>
    <div class="welcome-card mb-4">
        <div><span class="section-eyebrow text-primary">CHÀO MỪNG ĐẾN VỚI zaloMind</span><h2 class="mt-3 mb-2">Kết nối bền lâu.<br>Chăm sóc từ những điều nhỏ.</h2><p class="mb-4">Xin chào {{ auth()->user()->name }}, không gian làm việc của bạn đã sẵn sàng. Bắt đầu bằng việc kết nối tài khoản Zalo.</p><a href="{{ route('workspace.section', 'profiles') }}" class="btn btn-primary">Khám phá tài khoản Zalo<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a></div>
        <img src="{{ asset('brand/illustrations/login-hero.svg') }}" width="520" height="340" alt="" aria-hidden="true">
    </div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat-card label="Tài khoản" value="Đã sẵn sàng" icon="shield-check" hint="Đăng nhập và bảo mật" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Profile Zalo" value="Chưa kết nối" icon="person-badge" hint="Kết nối ở giai đoạn 4" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Chiến dịch" value="Chưa thiết lập" icon="chat-left-text" hint="Triển khai ở giai đoạn 6" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Gói dịch vụ" value="Chưa kích hoạt" icon="box" hint="Triển khai ở giai đoạn 10" /></div>
    </div>
    <div class="row g-4 mb-4">
        <div class="col-xl-8"><x-card title="Tài khoản Zalo của bạn" description="Quản lý kết nối và trạng thái tài khoản tại một nơi."><x-empty-state title="Chưa kết nối tài khoản Zalo" description="Khi chức năng kết nối được kích hoạt, bạn có thể thêm tài khoản bằng mã QR và quản lý ngay tại đây." illustration="empty-profiles" action="Xem tài khoản Zalo" :href="route('workspace.section', 'profiles')" /></x-card></div>
        <div class="col-xl-4"><x-card title="Bắt đầu cùng zaloMind" description="Từng bước xây dựng kết nối với khách hàng.">
            <div class="setup-step complete"><span class="step-number"><i class="bi bi-check-lg" aria-hidden="true"></i></span><div><strong>Tạo không gian làm việc</strong><p>Bạn đã đăng nhập và có tài khoản riêng.</p></div></div>
            <div class="setup-step"><span class="step-number">2</span><div><strong>Kết nối tài khoản Zalo</strong><p>Luồng đăng nhập QR sẽ có ở giai đoạn 4.</p></div></div>
            <div class="setup-step"><span class="step-number">3</span><div><strong>Tạo chiến dịch đầu tiên</strong><p>Chuẩn bị nội dung và tệp khách hàng ở các bước tiếp theo.</p></div></div>
            <div class="mt-3 p-3 rounded-3 bg-body-tertiary"><i class="bi bi-command text-primary me-2" aria-hidden="true"></i><span class="small text-body-secondary">Dùng <kbd>Ctrl K</kbd> để tìm trang nhanh.</span></div>
        </x-card></div>
    </div>
    <x-card title="Lối tắt cho bạn">
        <div class="row g-3">
            <div class="col-md-4"><a class="quick-action" href="{{ route('workspace.section', 'contact-lists') }}"><i class="bi bi-person-lines-fill" aria-hidden="true"></i><span><strong>Tệp khách hàng</strong><small>Chuẩn bị dữ liệu liên hệ</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></div>
            <div class="col-md-4"><a class="quick-action" href="{{ route('workspace.section', 'message-friends') }}"><i class="bi bi-chat-left-text" aria-hidden="true"></i><span><strong>Nhắn tin</strong><small>Kết nối đúng lúc</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></div>
            <div class="col-md-4"><a class="quick-action" href="{{ route('profile.edit') }}"><i class="bi bi-person-gear" aria-hidden="true"></i><span><strong>Tài khoản của tôi</strong><small>Thông tin và bảo mật</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></div>
        </div>
    </x-card>
</x-app-layout>
