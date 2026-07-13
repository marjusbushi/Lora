<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">

        @php($brand = \Illuminate\Support\Facades\Cache::get('app.settings', []))
        <title inertia>{{ $brand['hotel_name'] ?? 'Villa Mucho' }}</title>

        {{-- Favicon: the Villa Mucho "V" mark. It carries its own green background, so it
             stays legible at tab size on any background — unlike the uploaded wordmark logo
             (wide + white-on-transparent), which belongs in the site header, not a 16px tab. --}}
        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
        <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96.png?v=3">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
        <link rel="alternate icon" href="/favicon.ico?v=3">
        <meta name="theme-color" content="#2d6a4f">

        {{-- Installed-app (PWA) identity: Add to Home Screen opens standalone —
             no browser URL bar — on Android (manifest) and iOS (apple-* metas). --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ $brand['hotel_name'] ?? 'Villa Mucho' }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
