<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Chăm sóc khách hàng Zalo thông minh' }} · zaloMind</title>
<meta name="description" content="{{ config('zalomind.slogan') }}">
<meta name="theme-color" content="#1A56DB">
<meta property="og:title" content="zaloMind — Chăm sóc khách hàng Zalo thông minh">
<meta property="og:type" content="website">
@if (is_file(public_path('brand/favicon/favicon.svg')))
<link rel="icon" href="{{ asset('brand/favicon/favicon.svg') }}" type="image/svg+xml">
@endif
@if (is_file(public_path('brand/favicon/favicon.ico')))
<link rel="icon" href="{{ asset('brand/favicon/favicon.ico') }}" sizes="any">
@endif
@if (is_file(public_path('brand/favicon/apple-touch-icon.png')))
<link rel="apple-touch-icon" href="{{ asset('brand/favicon/apple-touch-icon.png') }}">
@endif
@if (is_file(public_path('brand/favicon/site.webmanifest')))
<link rel="manifest" href="{{ asset('brand/favicon/site.webmanifest') }}">
@endif
@if (is_file(public_path('brand/social/og-image.png')))
<meta property="og:image" content="{{ asset('brand/social/og-image.png') }}">
<meta name="twitter:image" content="{{ asset('brand/social/og-image.png') }}">
<meta name="twitter:card" content="summary_large_image">
@endif
<script>
    try { document.documentElement.dataset.bsTheme = localStorage.getItem('zalomind.theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch {}
    try { document.documentElement.dataset.sidebarCollapsed = localStorage.getItem('zalomind.sidebarCollapsed') || 'false'; } catch {}
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@if (is_file(public_path('brand/brand-tokens.css')))
<link rel="stylesheet" href="{{ asset('brand/brand-tokens.css') }}">
@endif
