<x-guest-layout>
    <h1 class="h3 fw-bold mb-3">Xác nhận mật khẩu</h1>
    <p class="text-body-secondary">Nhập mật khẩu để tiếp tục vào khu vực bảo mật.</p>
    <form method="POST" action="{{ route('password.confirm') }}">@csrf
        <div class="mb-4"><x-input-label for="password" value="Mật khẩu" /><x-text-input id="password" name="password" type="password" required autofocus autocomplete="current-password" /><x-input-error :messages="$errors->get('password')" /></div>
        <x-primary-button class="w-100">Xác nhận</x-primary-button>
    </form>
</x-guest-layout>
