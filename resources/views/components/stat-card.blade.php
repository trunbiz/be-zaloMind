@props(['label', 'value' => 0, 'icon' => 'bar-chart', 'hint' => null, 'trend' => null])
<div {{ $attributes->class('card stat-card h-100') }}><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-3"><span class="text-body-secondary small">{{ $label }}</span><span class="stat-icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span></div>
    <div class="stat-value mt-2" data-counter="{{ is_numeric($value) ? $value : '' }}">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : $value }}</div>
    @if($hint)<p class="small text-body-secondary mb-0 mt-2">{{ $hint }}</p>@endif
    @if($trend)<p class="small text-success mb-0 mt-2">{{ $trend }}</p>@endif
</div></div>

