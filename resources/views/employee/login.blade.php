@extends('layouts.employee', ['title' => 'Prihlásenie'])

@section('content')
<div class="mx-auto mt-6 max-w-sm">
    <h1 class="mb-1 text-2xl font-bold">Prihlásenie</h1>
    <p class="mb-6 text-stone-600">Objednávanie obedov a večerí pre zamestnancov.</p>

    <form method="POST" action="{{ route('login') }}" class="space-y-5 rounded-2xl bg-white p-5 shadow-sm">
        @csrf

        @if ($errors->any())
            <div class="rounded-xl border-2 border-red-300 bg-red-50 p-3 text-base font-semibold text-red-800" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label for="osobne_cislo" class="mb-1 block text-lg font-semibold">Osobné číslo</label>
            <input id="osobne_cislo" name="osobne_cislo" type="text" inputmode="numeric" autocomplete="username"
                   value="{{ old('osobne_cislo') }}" required autofocus
                   class="w-full rounded-xl border-2 border-stone-300 px-4 py-3 text-2xl tracking-wider focus:border-emerald-600 focus:outline-none">
        </div>

        <div>
            <label for="pin" class="mb-1 block text-lg font-semibold">PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="6" required
                   class="w-full rounded-xl border-2 border-stone-300 px-4 py-3 text-2xl tracking-[0.4em] focus:border-emerald-600 focus:outline-none">
        </div>

        <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-4 text-xl font-bold text-white active:bg-emerald-900">
            Prihlásiť sa
        </button>

        <p class="text-sm text-stone-600">Na tomto zariadení zostanete prihlásený. PIN ste dostali na lístku od administrátora.</p>
    </form>
</div>
@endsection
