@extends('layouts.employee', ['title' => 'Účet'])

@section('content')
@php $me = auth('employee')->user(); @endphp
<div class="mx-auto max-w-md space-y-4">
    @if (session('status'))
        <div class="rounded-xl bg-emerald-100 p-3 text-lg font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
    @endif

    <div class="rounded-2xl bg-white p-4 shadow-sm">
        <h1 class="text-2xl font-bold">{{ $me->fullName() }}</h1>
        <p class="text-lg text-stone-600">Osobné číslo: <strong>{{ $me->osobne_cislo }}</strong></p>
        @if ($me->department)
            <p class="text-base text-stone-600">{{ $me->department }}</p>
        @endif
    </div>

    <form method="POST" action="{{ route('pin.update') }}" class="space-y-4 rounded-2xl bg-white p-4 shadow-sm">
        @csrf
        @method('PUT')
        <h2 class="text-xl font-bold">Zmena PIN</h2>

        @if ($errors->any())
            <div class="rounded-xl border-2 border-red-300 bg-red-50 p-3 text-base font-semibold text-red-800" role="alert">{{ $errors->first() }}</div>
        @endif

        @foreach ([['current_pin', 'Súčasný PIN', 'current-password'], ['pin', 'Nový PIN (4 – 6 číslic)', 'new-password'], ['pin_confirmation', 'Nový PIN ešte raz', 'new-password']] as [$name, $label, $ac])
            <div>
                <label for="{{ $name }}" class="mb-1 block text-lg font-semibold">{{ $label }}</label>
                <input id="{{ $name }}" name="{{ $name }}" type="password" inputmode="numeric" maxlength="6" autocomplete="{{ $ac }}" required
                       class="w-full rounded-xl border-2 border-stone-300 px-4 py-3 text-2xl tracking-[0.4em] focus:border-emerald-600 focus:outline-none">
            </div>
        @endforeach

        <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-lg font-bold text-white active:bg-emerald-900">Zmeniť PIN</button>
    </form>

    <div class="rounded-2xl bg-white p-4 shadow-sm">
        <h2 class="mb-2 text-xl font-bold">Ikona na ploche telefónu</h2>
        <ul class="list-disc space-y-1 pl-5 text-base">
            <li><strong>Android (Chrome):</strong> ponuka ⋮ → <em>Pridať na plochu</em> / <em>Inštalovať aplikáciu</em>.</li>
            <li><strong>iPhone (Safari):</strong> tlačidlo Zdieľať □↑ → <em>Pridať na plochu</em>.</li>
            <li>Potom aplikáciu otvárajte ikonou <em>{{ app(\App\Support\Settings::class)->appName() }}</em>.</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded-xl border-2 border-stone-300 bg-white px-4 py-3 text-lg font-semibold active:bg-stone-100">Odhlásiť sa</button>
    </form>
</div>
@endsection
