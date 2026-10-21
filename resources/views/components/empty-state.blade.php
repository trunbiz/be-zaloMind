@props(['title' => 'Chưa có dữ liệu', 'description' => '', 'illustration' => 'empty-search', 'action' => null, 'href' => null])
<div {{ $attributes->class('empty-state text-center') }}>
    <img src="{{ asset('brand/illustrations/'.(in_array($illustration, ['empty-profiles', 'empty-batches', 'empty-inbox', 'empty-search', 'error-404']) ? $illustration : 'empty-search').'.svg') }}" width="220" height="157" alt="" aria-hidden="true">
    <h2 class="h6 fw-semibold mt-3">{{ $title }}</h2>
    @if($description)<p class="text-body-secondary small mx-auto mb-0">{{ $description }}</p>@endif
    @if($action && $href)<a class="btn btn-primary mt-4" href="{{ $href }}">{{ $action }}<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a>@endif
    {{ $slot }}
</div>

