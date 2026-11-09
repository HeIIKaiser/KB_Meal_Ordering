{{-- Self-contained error page (no Vite assets: must work even when the app is broken). --}}
@php $home = request()->is('admin*') ? url('/admin') : url('/'); @endphp
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#047857">
    <meta name="robots" content="noindex">
    <title>@yield('title') – Jedáleň</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: #f5f5f4; color: #1c1917; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; box-sizing: border-box; text-align: center; }
        main { max-width: 26rem; }
        .code { font-size: .95rem; font-weight: 700; color: #78716c; letter-spacing: .1em; }
        h1 { font-size: 1.6rem; margin: .4rem 0 .6rem; }
        p { font-size: 1.1rem; line-height: 1.5; margin: 0 0 1.6rem; }
        a, button { font: inherit; font-size: 1.15rem; font-weight: 700; background: #047857; color: #fff; border: 0; border-radius: .75rem; padding: .9rem 1.8rem; text-decoration: none; display: inline-block; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        @hasSection('action')
            @yield('action')
        @else
            <a href="{{ $home }}">Späť na úvod</a>
        @endif
    </main>
</body>
</html>
