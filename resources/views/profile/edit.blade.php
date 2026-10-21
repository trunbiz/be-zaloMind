<x-app-layout title="Tài khoản của tôi">
    <x-slot name="header"><h1 class="h3 fw-bold">Tài khoản của tôi</h1></x-slot>
    @if (session('status'))<div class="alert alert-success" role="status">Đã lưu thay đổi.</div>@endif
    <div class="row g-4">
        <section class="col-lg-6"><div class="card p-4">
            <h2 class="h5 mb-4">Thông tin tài khoản</h2>
            <form method="POST" action="{{ route('profile.update') }}">@csrf @method('PATCH')
                <div class="mb-3"><x-input-label for="name" value="Họ và tên" /><x-text-input id="name" name="name" :value="old('name', $user->name)" required autocomplete="name" /><x-input-error :messages="$errors->get('name')" /></div>
                <div class="mb-3"><x-input-label for="email" value="Địa chỉ email" /><x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" required autocomplete="email" /><x-input-error :messages="$errors->get('email')" /></div>
                <x-primary-button>Lưu thông tin</x-primary-button>
            </form>
        </div></section>
        <section class="col-lg-6"><div class="card p-4">
            <h2 class="h5 mb-4">Đổi mật khẩu</h2>
            <form method="POST" action="{{ route('password.update') }}">@csrf @method('PUT')
                <div class="mb-3"><x-input-label for="current_password" value="Mật khẩu hiện tại" /><x-text-input id="current_password" name="current_password" type="password" required autocomplete="current-password" /><x-input-error :messages="$errors->updatePassword->get('current_password')" /></div>
                <div class="mb-3"><x-input-label for="password" value="Mật khẩu mới" /><x-text-input id="password" name="password" type="password" required autocomplete="new-password" /><x-input-error :messages="$errors->updatePassword->get('password')" /></div>
                <div class="mb-3"><x-input-label for="password_confirmation" value="Xác nhận mật khẩu mới" /><x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /></div>
                <x-primary-button>Đổi mật khẩu</x-primary-button>
            </form>
        </div></section>
        <section class="col-12"><div class="card p-4">
            <h2 class="h5 text-danger">Xóa tài khoản</h2>
            <p class="text-body-secondary">Tài khoản bị xóa sẽ không thể khôi phục. Nhập mật khẩu để xác nhận.</p>
            <form method="POST" action="{{ route('profile.destroy') }}" data-confirm="Bạn chắc chắn muốn xóa vĩnh viễn tài khoản?">@csrf @method('DELETE')
                <div class="mb-3"><x-input-label for="delete_password" value="Mật khẩu" /><x-text-input id="delete_password" name="password" type="password" required autocomplete="current-password" /><x-input-error :messages="$errors->userDeletion->get('password')" /></div>
                <button type="submit" class="btn btn-danger">Xóa tài khoản</button>
            </form>
        </div></section>
    </div>
</x-app-layout>
