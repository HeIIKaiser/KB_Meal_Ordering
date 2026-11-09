@php
    use App\Support\Money;
    use App\Support\SlovakDate;
@endphp
@extends('layouts.employee', ['title' => 'Môj mesiac'])

@section('content')
<div class="mx-auto max-w-2xl">
    <div class="mb-3 flex items-center justify-between gap-2">
        <a href="{{ route('month', ['mesiac' => $prev]) }}" class="rounded-xl bg-white px-4 py-3 text-lg font-bold shadow-sm active:bg-stone-200" aria-label="Predchádzajúci mesiac">‹</a>
        <h1 class="text-center text-2xl font-bold capitalize">{{ SlovakDate::monthYear($month) }}</h1>
        <a href="{{ route('month', ['mesiac' => $next]) }}" class="rounded-xl bg-white px-4 py-3 text-lg font-bold shadow-sm active:bg-stone-200" aria-label="Nasledujúci mesiac">›</a>
    </div>

    <div class="mb-4 rounded-2xl bg-emerald-700 p-4 text-white shadow-sm">
        <div class="flex items-baseline justify-between">
            <span class="text-lg font-semibold">Spolu</span>
            <span class="text-3xl font-bold">{{ Money::format($totalCents) }}</span>
        </div>
        <div class="mt-1 text-base opacity-90">
            {{ $orders->count() }} {{ $orders->count() === 1 ? 'jedlo' : ($orders->count() >= 2 && $orders->count() <= 4 ? 'jedlá' : 'jedál') }}
            @foreach ($byType as $name => $t)
                · {{ $name }}: {{ $t['count'] }} ks ({{ Money::format($t['cents']) }})
            @endforeach
        </div>
        @if ($isCurrent)
            <div class="mt-1 text-sm opacity-80">Vrátane objednávok na zvyšok mesiaca.</div>
        @endif
        <div class="mt-2 border-t border-white/30 pt-2 text-sm font-semibold">{{ Money::ESTIMATE_DISCLAIMER }}</div>
    </div>

    @forelse ($byDay as $ymd => $dayOrders)
        @php $d = $dayOrders->first()->date; @endphp
        <div class="mb-2 rounded-2xl bg-white p-3 shadow-sm">
            <div class="mb-1 text-base font-bold"><span class="capitalize">{{ SlovakDate::day($d) }}</span> {{ SlovakDate::dm($d) }}</div>
            <ul class="divide-y divide-stone-100">
                @foreach ($dayOrders as $o)
                    <li class="flex items-start justify-between gap-3 py-1.5">
                        <span class="text-base">
                            <span class="font-semibold">{{ $o->mealSlot->name }}</span> –
                            {{ $o->menuItem->label() }}: {{ $o->menuItem->name }}
                            @unless ($o->priceType->isDonated())
                                <span class="ml-1 rounded bg-amber-400 px-1.5 text-sm font-bold text-black">{{ $o->priceType->name }}</span>
                            @endunless
                        </span>
                        <span class="whitespace-nowrap text-base font-semibold">{{ Money::format($o->unit_price_cents) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="rounded-2xl bg-white p-6 text-center text-lg text-stone-600 shadow-sm">V tomto mesiaci nemáte žiadne objednávky.</p>
    @endforelse
</div>
@endsection
