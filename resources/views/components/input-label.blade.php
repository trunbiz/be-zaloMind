@props(['value'])
<label {{ $attributes->class('form-label') }}>{{ $value ?? $slot }}</label>
