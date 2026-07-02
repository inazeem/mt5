<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'MT5 Bot') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script>
            (function () {
                const saved = localStorage.getItem('theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', saved === 'dark' || (! saved && prefersDark));
            })();
        </script>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-slate-100 px-4 py-12 dark:bg-slate-950">
            <a href="/" class="mb-8 flex items-center gap-2 font-bold text-slate-900 dark:text-white">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-white">M5</span>
                {{ config('app.name', 'MT5 Bot') }}
            </a>
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-lg dark:border-slate-800 dark:bg-slate-900">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
