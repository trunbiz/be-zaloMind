<x-guest-layout>
    <h1 class="h3 fw-bold">Quên mật khẩu?</h1>
    <p class="text-body-secondary mb-4">Nhập email để nhận liên kết đặt lại mật khẩu.</p>
    <x-auth-session-status :status="session('status')" />
    <form method="POST" action="{{ route('password.email') }}">@csrf
        <div class="mb-4"><x-input-label for="email" value="Địa chỉ email" /><x-text-input id="email" name="email" type="email" :value="old('email')" required autofocus autocomplete="email" /><x-input-error :messages="$errors->get('email')" /></div>
        <x-primary-button class="w-100">Gửi liên kết đặt lại mật khẩu</x-primary-button>
    </form>
    <p class="small text-center mt-4"><a href="{{ route('login') }}">Trở về đăng nhập</a></p>
</x-guest-layout>
