@props(['title' => null, 'description' => null])
<section {{ $attributes->class('card') }}>
    @if($title || isset($actions))
    <div class="card-header d-flex justify-content-between align-items-start gap-3">
        <div>@if($title)<h2 class="h6 mb-0 fw-semibold">{{ $title }}</h2>@endif
            @if($description)<p class="small text-body-secondary mb-0 mt-2">{{ $description }}</p>@endif</div>
        @isset($actions)<div class="flex-shrink-0">{{ $actions }}</div>@endisset
    </div>
    @endif
    <div class="card-body">{{ $slot }}</div>
    @isset($footer)<div class="card-footer">{{ $footer }}</div>@endisset
</section>

