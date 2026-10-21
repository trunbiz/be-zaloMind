<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="workspace-sidebar" aria-labelledby="sidebar-title">
    <div class="sidebar-brand">
        <a href="{{ route($admin ? 'admin.dashboard' : 'dashboard') }}" class="sidebar-brand-full" aria-label="Trang chủ zaloMind"><x-logo /></a>
        <a href="{{ route($admin ? 'admin.dashboard' : 'dashboard') }}" class="sidebar-brand-mark" aria-label="Trang chủ zaloMind"><x-logo variant="mark" /></a>
        @if($admin)<span class="badge admin-label">Admin</span>@endif
        <button type="button" class="btn-close d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#workspace-sidebar" aria-label="Đóng menu"></button>
        <span id="sidebar-title" class="visually-hidden">{{ $admin ? 'Menu quản trị' : 'Menu làm việc' }}</span>
    </div>
    <div class="sidebar-nav-wrap">
        <div class="sidebar-section-label">{{ $admin ? 'QUẢN TRỊ HỆ THỐNG' : 'KHÔNG GIAN LÀM VIỆC' }}</div>
        <nav aria-label="{{ $admin ? 'Điều hướng quản trị' : 'Điều hướng làm việc' }}" class="sidebar-nav" id="sidebar-navigation">
            @foreach($navigation as $item)
                @if(isset($item['children']))
                <div @class(['nav-group', 'is-active' => $item['active']]) data-nav-group>
                    <button type="button" @class(['sidebar-link', 'active' => $item['active']]) data-nav-toggle aria-expanded="{{ $item['active'] ? 'true' : 'false' }}" aria-controls="submenu-{{ $item['key'] }}" aria-label="{{ $item['label'] }}">
                        <i class="bi bi-{{ $item['icon'] }} nav-icon" aria-hidden="true"></i><span class="nav-label">{{ $item['label'] }}</span><i class="bi bi-chevron-down nav-chevron" aria-hidden="true"></i>
                    </button>
                    <div id="submenu-{{ $item['key'] }}" class="sidebar-submenu" @if(!$item['active']) hidden @endif>
                        <div class="flyout-title">{{ $item['label'] }}</div>
                        @foreach($item['children'] as $child)
                        <a href="{{ $child['url'] }}" @class(['sidebar-sublink', 'active' => $child['active']]) @if($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                        @endforeach
                    </div>
                </div>
                @else
                <a href="{{ $item['url'] }}" @class(['sidebar-link', 'active' => $item['active']]) aria-label="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif>
                    <i class="bi bi-{{ $item['icon'] }} nav-icon" aria-hidden="true"></i><span class="nav-label">{{ $item['label'] }}</span>
                </a>
                @endif
            @endforeach
        </nav>
    </div>
    <div class="sidebar-footer">
        @if($admin)
        <div class="sidebar-plan"><i class="bi bi-shield-check text-primary" aria-hidden="true"></i><div><strong>Không gian quản trị</strong><span>Truy cập theo phân quyền</span></div></div>
        @else
        <a href="{{ route('workspace.section', 'billing') }}" class="sidebar-plan text-decoration-none"><i class="bi bi-box text-primary" aria-hidden="true"></i><div><strong>Chưa đăng ký gói</strong><span>Hạn mức sẽ hiển thị sau khi kích hoạt</span></div><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        @endif
        <button type="button" class="sidebar-collapse-button d-none d-lg-flex" data-sidebar-toggle aria-label="Thu gọn hoặc mở rộng menu" aria-expanded="true"><i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i><span class="nav-label">Thu gọn menu</span></button>
    </div>
</aside>

