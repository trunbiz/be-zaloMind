@props(['id', 'title', 'size' => null])
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true" {{ $attributes }}>
    <div @class(['modal-dialog modal-dialog-centered', 'modal-lg' => $size === 'lg', 'modal-xl' => $size === 'xl', 'modal-sm' => $size === 'sm'])>
        <div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
            <div class="modal-body">{{ $slot }}</div>
            @isset($footer)<div class="modal-footer">{{ $footer }}</div>@endisset
        </div>
    </div>
</div>

