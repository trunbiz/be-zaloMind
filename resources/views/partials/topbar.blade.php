<header class="app-topbar">
    <div class="topbar-left">
        <button type="button" class="icon-button d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#workspace-sidebar" aria-controls="workspace-sidebar" aria-label="Mở menu"><i class="bi bi-list" aria-hidden="true"></i></button>
        <nav aria-label="Đường dẫn"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="{{ route($admin ? 'admin.dashboard' : 'dashboard') }}">{{ $admin ? 'Quản trị' : 'Không gian làm việc' }}</a></li><li class="breadcrumb-item active" aria-current="page">{{ $title }}</li></ol></nav>
        <span class="topbar-title">{{ $title }}</span>
    </div>
    <div class="topbar-actions">
        <label class="quick-profile d-none d-xl-flex"><i class="bi bi-person-badge" aria-hidden="true"></i><span class="visually-hidden">Profile Zalo đang làm việc</span><select class="form-select form-select-sm" disabled aria-label="Profile Zalo đang làm việc"><option>Chưa có profile Zalo</option></select></label>
        <button type="button" class="command-trigger" data-bs-toggle="modal" data-bs-target="#command-palette" aria-label="Tìm kiếm trang, phím tắt Control K"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-xl-inline">Tìm nhanh</span><kbd class="d-none d-xl-inline">Ctrl K</kbd></button>
        <button type="button" class="icon-button" data-theme-toggle aria-label="Chuyển giao diện sáng tối"><i class="bi bi-circle-half" aria-hidden="true"></i></button>
        <div class="dropdown">
            <button type="button" class="icon-button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Thông báo"><i class="bi bi-bell" aria-hidden="true"></i></button>
            <div class="dropdown-menu dropdown-menu-end notifications-menu"><h2 class="h6 px-3 pt-2">Thông báo</h2><div class="p-3 text-center"><i class="bi bi-bell-slash text-body-tertiary fs-3" aria-hidden="true"></i><p class="small text-body-secondary mt-2 mb-0">Bạn chưa có thông báo mới.</p></div></div>
        </div>
        <div class="topbar-divider"></div>
        <div class="dropdown">
            <button type="button" class="account-trigger" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu tài khoản {{ auth()->user()->name }}"><span class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="account-text d-none d-md-block"><strong>{{ auth()->user()->name }}</strong><small>{{ $admin ? 'Quản trị viên' : 'Tài khoản cá nhân' }}</small></span><i class="bi bi-chevron-down d-none d-md-inline" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2"><span class="small text-body-secondary d-block text-break">{{ auth()->user()->email }}</span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person me-2" aria-hidden="true"></i>Tài khoản của tôi</a></li>
                @role('admin')<li><a class="dropdown-item" href="{{ route($admin ? 'dashboard' : 'admin.dashboard') }}"><i class="bi bi-arrow-left-right me-2" aria-hidden="true"></i>{{ $admin ? 'Không gian người dùng' : 'Khu vực quản trị' }}</a></li>@endrole
                <li><hr class="dropdown-divider"></li>
                <li><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Đăng xuất</button></form></li>
            </ul>
        </div>
    </div>
</header>

