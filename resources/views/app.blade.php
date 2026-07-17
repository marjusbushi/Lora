<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">

        @php($isLoraMarketing = ($page['component'] ?? null) === 'Marketing/Home')
        @php($hasTenant = ! empty($page['props']['tenant'] ?? null))
        @php($hotelName = $page['props']['settings']['hotel_name'] ?? 'Lora PMS')
        <title inertia>{{ $isLoraMarketing ? 'Lora PMS — Menaxho hotelin. Jo kaosin.' : $hotelName }}</title>

        @if ($isLoraMarketing)
            <link rel="icon" type="image/svg+xml" href="/lora-favicon.svg?v=1">
            <meta name="theme-color" content="#123d32">
        @else
            {{-- Hotel booking websites retain their own established favicon. --}}
            <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
            <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96.png?v=3">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
            <link rel="alternate icon" href="/favicon.ico?v=3">
            <meta name="theme-color" content="#2d6a4f">
        @endif

        @if ($hasTenant)
            {{-- Installed-app (PWA) identity: Add to Home Screen opens standalone —
                 no browser URL bar — on Android (manifest) and iOS (apple-* metas). --}}
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
            <meta name="apple-mobile-web-app-title" content="{{ $hotelName }}">
        @endif

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(in_array(strtolower(request()->getHost()), config('lora.control_panel_hosts', []), true) ? null : 'hotel')
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
