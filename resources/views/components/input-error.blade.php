@props(['messages'])
@if ($messages)
<ul {{ $attributes->class('text-danger small mt-2 list-unstyled') }}>
    @foreach ((array) $messages as $message)<li>{{ $message }}</li>@endforeach
</ul>
@endif
