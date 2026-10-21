@props(['status', 'label' => null])
@php
    $states = [
        'hot' => ['success', 'Đang hoạt động'], 'warm' => ['warning', 'Sẵn sàng'],
        'cold' => ['secondary', 'Đang nghỉ'], 'pending_login' => ['warning', 'Chờ đăng nhập'],
        'expired' => ['danger', 'Hết phiên'], 'error' => ['danger', 'Lỗi'],
        'completed' => ['success', 'Hoàn thành'], 'success' => ['success', 'Thành công'],
        'running' => ['warning', 'Đang chạy'], 'pending' => ['warning', 'Đang chờ'],
        'failed' => ['danger', 'Thất bại'], 'paused' => ['secondary', 'Tạm dừng'],
        'disabled' => ['secondary', 'Đã tắt'],
    ];
    [$color, $text] = $states[$status instanceof \BackedEnum ? $status->value : $status] ?? ['secondary', 'Chưa xác định'];
@endphp
<span {{ $attributes->class('badge status-badge text-'.$color.'-emphasis bg-'.$color.'-subtle') }}><span class="status-dot" aria-hidden="true"></span>{{ $label ?? $text }}</span>

