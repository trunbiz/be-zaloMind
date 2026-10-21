<x-guest-layout>
    <h1 class="h3 fw-bold">Chào mừng trở lại</h1>
    <p class="text-body-secondary mb-4">Đăng nhập để tiếp tục chăm sóc khách hàng.</p>
    <x-auth-session-status class="mb-4" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="mb-3">
            <x-input-label for="email" value="Địa chỉ email" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>
        <div class="mb-3">
            <x-input-label for="password" value="Mật khẩu" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-4 small">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="remember"> Ghi nhớ đăng nhập</label>
            <a href="{{ route('password.request') }}">Quên mật khẩu?</a>
        </div>
        <x-primary-button class="w-100">Đăng nhập <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></x-primary-button>
    </form>
    <p class="text-center small mt-4">Chưa có tài khoản? <a href="{{ route('register') }}">Đăng ký ngay</a></p>
</x-guest-layout>
