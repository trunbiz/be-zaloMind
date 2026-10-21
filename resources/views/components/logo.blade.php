@props(['variant' => 'full'])
@php
    $name = match ($variant) { 'stacked' => 'zalomind-logo-stacked', 'mark' => 'zalomind-mark', default => 'zalomind-logo' };
    $light = 'brand/logo/'.$name.'.svg';
    $dark = in_array($variant, ['full', 'stacked']) ? 'brand/logo/'.$name.'-dark.svg' : $light;
@endphp
<img {{ $attributes->class(['brand-logo', 'brand-logo-stacked' => $variant === 'stacked', 'brand-logo-mark' => $variant === 'mark']) }} src="{{ asset($light) }}" data-logo-light="{{ asset($light) }}" data-logo-dark="{{ asset($dark) }}" alt="zaloMind" @if($variant === 'mark') width="32" height="32" @else width="222" height="{{ $variant === 'stacked' ? '134' : '54' }}" @endif>
