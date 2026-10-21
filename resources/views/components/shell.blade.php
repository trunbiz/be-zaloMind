@props(['admin' => false, 'title' => 'Tổng quan', 'header' => null])
@php
    $navigationService = app(\App\Services\Navigation::class);
    $area = $admin ? 'admin' : 'user';
    $navigation = $navigationService->items($area, auth()->user());
    $commands = $navigationService->pages($area, auth()->user());
    $commands[] = ['label' => 'Tài khoản của tôi', 'url' => route('profile.edit'), 'icon' => 'person-gear', 'group' => 'Tài khoản'];
    if (auth()->user()->hasRole('admin')) {
        $commands[] = ['label' => $admin ? 'Không gian người dùng' : 'Khu vực quản trị', 'url' => route($admin ? 'dashboard' : 'admin.dashboard'), 'icon' => 'arrow-left-right', 'group' => 'Chuyển không gian'];
    }
@endphp
<!DOCTYPE html>
<html lang="vi" data-bs-theme="light">
<head>@include('partials.head', ['title' => $title])</head>
<body @class(['workspace', 'admin-workspace' => $admin])>
    <a class="skip-link" href="#main-content">Chuyển đến nội dung chính</a>
    <div class="page-progress" aria-hidden="true"></div>
    @include('partials.sidebar')
    <div class="workspace-main">
        @include('partials.topbar')
        <main id="main-content" class="workspace-content page-content" tabindex="-1">
            <div class="page-heading">
                <div>@if($header){{ $header }}@else<h1>{{ $title }}</h1>@endif</div>
                <span class="workspace-date d-none d-md-inline"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>{{ now()->format('d/m/Y') }}</span>
            </div>
            {{ $slot }}
            <footer class="workspace-footer"><span>© {{ now()->year }} zaloMind</span><span>Chăm sóc khách hàng Zalo thông minh</span></footer>
        </main>
    </div>
    @include('partials.command-palette')
    @include('partials.feedback')
</body>
</html>
