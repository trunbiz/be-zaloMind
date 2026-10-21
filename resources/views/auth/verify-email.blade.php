<x-guest-layout>
    <h1 class="h3 fw-bold mb-3">Xác minh địa chỉ email</h1>
    <p class="text-body-secondary">Nhấn vào liên kết trong email chúng tôi đã gửi để xác minh tài khoản của bạn.</p>
    @if(session('status') === 'verification-link-sent')<div class="alert alert-success" role="status">Đã gửi liên kết xác minh mới.</div>@endif
    <form method="POST" action="{{ route('verification.send') }}">@csrf<x-primary-button class="w-100">Gửi lại email xác minh</x-primary-button></form>
    <form class="mt-3" method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-secondary w-100" type="submit">Đăng xuất</button></form>
</x-guest-layout>
