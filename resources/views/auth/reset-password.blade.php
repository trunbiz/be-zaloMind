<x-guest-layout>
    <h1 class="h3 fw-bold mb-4">Đặt lại mật khẩu</h1>
    <form method="POST" action="{{ route('password.store') }}">@csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="mb-3"><x-input-label for="email" value="Địa chỉ email" /><x-text-input id="email" name="email" type="email" :value="old('email', $request->email)" required autofocus autocomplete="username" /><x-input-error :messages="$errors->get('email')" /></div>
        <div class="mb-3"><x-input-label for="password" value="Mật khẩu mới" /><x-text-input id="password" name="password" type="password" required autocomplete="new-password" /><x-input-error :messages="$errors->get('password')" /></div>
        <div class="mb-4"><x-input-label for="password_confirmation" value="Xác nhận mật khẩu" /><x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /></div>
        <x-primary-button class="w-100">Đặt lại mật khẩu</x-primary-button>
    </form>
</x-guest-layout>
