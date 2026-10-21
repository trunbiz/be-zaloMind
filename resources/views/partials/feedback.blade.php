<div class="toast-container position-fixed top-0 end-0 p-3" aria-live="polite" aria-atomic="true">
    <div id="app-toast" class="toast" role="status" data-bs-delay="4500"><div class="toast-header"><i class="bi bi-check-circle text-success me-2" aria-hidden="true"></i><strong class="me-auto">zaloMind</strong><button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Đóng thông báo"></button></div><div class="toast-body"></div></div>
</div>
@if(session('status') || session('success') || session('error'))
<div hidden data-flash-message="{{ session('error') ?? session('success') ?? (in_array(session('status'), ['profile-updated', 'password-updated']) ? 'Đã lưu thay đổi thành công.' : session('status')) }}" data-flash-error="{{ session()->has('error') ? 'true' : 'false' }}"></div>
@endif
<x-modal id="confirm-action" title="Xác nhận thao tác" size="sm">
    <p class="mb-0" data-confirm-message></p>
    <x-slot name="footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button><button type="button" class="btn btn-danger" data-confirm-accept>Xác nhận</button></x-slot>
</x-modal>

