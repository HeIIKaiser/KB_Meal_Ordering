@extends('layouts.employee', ['title' => 'Prihlásenie'])

@section('content')
<div class="mx-auto mt-6 max-w-sm">
    @if ($company = app(\App\Support\Settings::class)->companyName())
        <p class="mb-1 text-lg font-semibold text-secondary">{{ $company }}</p>
    @endif
    <h1 class="mb-1 text-2xl font-bold">Prihlásenie</h1>
    <p class="mb-6 text-neutral">Objednávanie obedov a večerí pre zamestnancov.</p>

    <form method="POST" action="{{ route('login') }}" class="card space-y-5 bg-base-100 p-5 shadow-sm">
        @csrf

        @if ($errors->any())
            <div class="alert border-error bg-error-soft text-base font-semibold text-error" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <fieldset class="fieldset p-0">
            <label for="osobne_cislo" class="fieldset-legend pt-0 text-lg font-semibold">Osobné číslo</label>
            <input id="osobne_cislo" name="osobne_cislo" type="text" inputmode="numeric" autocomplete="username"
                   value="{{ old('osobne_cislo') }}" required autofocus
                   class="input input-lg h-14 w-full focus:outline-primary focus-within:outline-primary text-2xl tracking-wider">
        </fieldset>

        <fieldset class="fieldset p-0">
            <label for="pin" class="fieldset-legend pt-0 text-lg font-semibold">PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="6" required
                   class="input input-lg h-14 w-full focus:outline-primary focus-within:outline-primary text-2xl tracking-[0.4em]">
        </fieldset>

        <button type="submit" class="btn btn-primary btn-lg btn-block h-16 text-xl font-bold">
            Prihlásiť sa
        </button>

        <p class="text-sm text-neutral">Na tomto zariadení zostanete prihlásený. PIN ste dostali na lístku od administrátora.</p>
    </form>
</div>
@endsection
