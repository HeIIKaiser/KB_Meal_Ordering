@extends('layouts.employee', ['title' => 'Účet'])

@section('content')
@php $me = auth('employee')->user(); @endphp
<div class="mx-auto max-w-md space-y-4">
    @if (session('status'))
        <div class="alert alert-success text-lg font-semibold" role="status">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 p-4 shadow-sm">
        <h1 class="text-2xl font-bold">{{ $me->fullName() }}</h1>
        <p class="text-lg text-neutral">Osobné číslo: <strong>{{ $me->osobne_cislo }}</strong></p>
        @if ($me->department)
            <p class="text-base text-neutral">{{ $me->department }}</p>
        @endif
    </div>

    <form method="POST" action="{{ route('pin.update') }}" class="card space-y-4 bg-base-100 p-4 shadow-sm">
        @csrf
        @method('PUT')
        <h2 class="text-xl font-bold">Zmena PIN</h2>

        @if ($errors->any())
            <div class="alert border-error bg-error-soft text-base font-semibold text-error" role="alert">{{ $errors->first() }}</div>
        @endif

        @foreach ([['current_pin', 'Súčasný PIN', 'current-password'], ['pin', 'Nový PIN (4 – 6 číslic)', 'new-password'], ['pin_confirmation', 'Nový PIN ešte raz', 'new-password']] as [$name, $label, $ac])
            <fieldset class="fieldset p-0">
                <label for="{{ $name }}" class="fieldset-legend pt-0 text-lg font-semibold">{{ $label }}</label>
                <input id="{{ $name }}" name="{{ $name }}" type="password" inputmode="numeric" maxlength="6" autocomplete="{{ $ac }}" required
                       class="input input-lg h-14 w-full focus:outline-primary focus-within:outline-primary text-2xl tracking-[0.4em]">
            </fieldset>
        @endforeach

        <button type="submit" class="btn btn-primary btn-lg btn-block h-14 text-lg font-bold">Zmeniť PIN</button>
    </form>

    <div class="card bg-base-100 p-4 shadow-sm">
        <h2 class="mb-2 text-xl font-bold">Ikona na ploche telefónu</h2>
        <ul class="list-disc space-y-1 pl-5 text-base">
            <li><strong>Android (Chrome):</strong> ponuka ⋮ → <em>Pridať na plochu</em> / <em>Inštalovať aplikáciu</em>.</li>
            <li><strong>iPhone (Safari):</strong> tlačidlo Zdieľať □↑ → <em>Pridať na plochu</em>.</li>
            <li>Potom aplikáciu otvárajte ikonou <em>{{ app(\App\Support\Settings::class)->appName() }}</em>.</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-lg btn-block h-14 border-base-300 bg-base-100 text-lg font-semibold">Odhlásiť sa</button>
    </form>
</div>
@endsection
