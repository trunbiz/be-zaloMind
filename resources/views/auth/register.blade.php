<x-guest-layout>
    <h1 class="h3 fw-bold">Tạo tài khoản zaloMind</h1>
    <p class="text-body-secondary mb-4">Bắt đầu chăm sóc khách hàng thông minh hơn.</p>
    <form method="POST" action="{{ route('register') }}">@csrf
        <div class="mb-3"><x-input-label for="name" value="Họ và tên" /><x-text-input id="name" name="name" :value="old('name')" required autofocus autocomplete="name" /><x-input-error :messages="$errors->get('name')" /></div>
        <div class="mb-3"><x-input-label for="email" value="Địa chỉ email" /><x-text-input id="email" name="email" type="email" :value="old('email')" required autocomplete="username" /><x-input-error :messages="$errors->get('email')" /></div>
        <div class="mb-3"><x-input-label for="password" value="Mật khẩu" /><x-text-input id="password" name="password" type="password" required autocomplete="new-password" /><x-input-error :messages="$errors->get('password')" /></div>
        <div class="mb-4"><x-input-label for="password_confirmation" value="Xác nhận mật khẩu" /><x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /><x-input-error :messages="$errors->get('password_confirmation')" /></div>
        <x-primary-button class="w-100">Đăng ký</x-primary-button>
    </form>
    <p class="small text-center mt-4">Đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập</a></p>
</x-guest-layout>
