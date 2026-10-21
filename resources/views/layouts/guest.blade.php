<!DOCTYPE html>
<html lang="vi" data-bs-theme="light">
<head>@include('partials.head', ['title' => 'Chào mừng'])</head>
<body>
<main class="auth-shell">
    <aside class="auth-story">
        <span class="section-eyebrow text-primary mb-3">KẾT NỐI TỐT HƠN, CHĂM SÓC THÔNG MINH HƠN</span>
        <h1 class="fw-bold">{{ config('zalomind.slogan') }}</h1>
        <p class="mt-3 text-body-secondary">Dành thời gian cho những điều quan trọng. Để zaloMind giúp bạn chăm sóc từng mối quan hệ.</p>
        @if (is_file(public_path('brand/illustrations/login-hero.svg')))
            <img class="auth-hero" src="{{ asset('brand/illustrations/login-hero.svg') }}" alt="" aria-hidden="true">
        @endif
        <ul class="list-unstyled mt-4 d-grid gap-3">
            <li><i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Quản lý nhiều tài khoản tại một nơi</li>
            <li><i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Chăm sóc khách hàng theo lịch của bạn</li>
            <li><i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Theo dõi hiệu quả rõ ràng, dễ dàng</li>
        </ul>
    </aside>
    <section class="auth-form-panel">
        <button class="btn btn-outline-secondary theme-toggle" type="button" data-theme-toggle aria-label="Chuyển giao diện sáng tối"><i class="bi bi-circle-half" aria-hidden="true"></i></button>
        <div class="auth-form page-content">
            <a class="brand-clearspace text-decoration-none mb-4" href="{{ url('/') }}" aria-label="Trang chủ zaloMind"><x-logo variant="stacked" /></a>
            {{ $slot }}
            <p class="auth-note">© {{ now()->year }} zaloMind · Kết nối bền lâu.</p>
        </div>
    </section>
</main>
</body>
</html>
