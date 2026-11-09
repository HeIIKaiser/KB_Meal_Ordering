<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ isset($title) ? $title.' – ' : '' }}Jedáleň</title>
    <meta name="theme-color" content="#047857">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Jedáleň">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-stone-100 text-stone-900 antialiased">
    @php $me = auth('employee')->user(); @endphp

    <header class="sticky top-0 z-30 bg-emerald-700 text-white shadow">
        <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('home') }}" class="text-xl font-bold tracking-tight">Jedáleň</a>
            @if ($me)
                <nav class="hidden items-center gap-1 text-base font-semibold sm:flex" aria-label="Hlavná ponuka">
                    <a href="{{ route('home') }}" @class(['rounded-lg px-3 py-2', 'bg-emerald-900' => request()->routeIs('home')])>Objednávky</a>
                    <a href="{{ route('month') }}" @class(['rounded-lg px-3 py-2', 'bg-emerald-900' => request()->routeIs('month')])>Môj mesiac</a>
                    <a href="{{ route('account') }}" @class(['rounded-lg px-3 py-2', 'bg-emerald-900' => request()->routeIs('account')])>Účet</a>
                </nav>
                <span class="truncate text-sm opacity-90 sm:hidden">{{ $me->fullName() }}</span>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-[1600px] px-3 pb-28 pt-3 sm:px-4 sm:pb-10">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @if ($me)
        {{-- Bottom navigation on phones --}}
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-300 bg-white pb-[env(safe-area-inset-bottom)] sm:hidden" aria-label="Hlavná ponuka">
            <div class="grid grid-cols-3 text-center text-sm font-semibold">
                @foreach ([['home', 'Objednávky', 'M4 6h16M4 12h16M4 18h10'], ['month', 'Môj mesiac', 'M7 3v3m10-3v3M4 9h16M5 6h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1z'], ['account', 'Účet', 'M12 12a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0']] as [$route, $label, $icon])
                    <a href="{{ route($route) }}" @class(['flex flex-col items-center gap-1 py-2.5', 'text-emerald-700' => request()->routeIs($route), 'text-stone-600' => ! request()->routeIs($route)])>
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
