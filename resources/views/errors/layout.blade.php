<!DOCTYPE html>
<html lang="vi" data-bs-theme="light">
<head>@include('partials.head', ['title' => $errorTitle])</head>
<body>
<main class="error-page"><div class="error-content">
    <a href="{{ url('/') }}" class="brand-clearspace" aria-label="Trang chủ zaloMind"><x-logo /></a>
    <img src="{{ asset('brand/illustrations/error-404.svg') }}" width="280" height="200" alt="" aria-hidden="true">
    <p class="error-code">{{ $errorCode }}</p>
    <h1>{{ $errorTitle }}</h1>
    <p class="text-body-secondary mt-3 mb-4">{{ $errorDescription }}</p>
    <a class="btn btn-primary" href="{{ url('/') }}"><i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Về trang chủ</a>
</div></main>
</body>
</html>
