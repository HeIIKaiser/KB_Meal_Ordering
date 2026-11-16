@php
    use App\Support\Money;
    use App\Support\SlovakDate;
@endphp
@extends('layouts.employee', ['title' => 'Môj mesiac'])

@section('content')
<div class="mx-auto max-w-2xl">
    <div class="mb-3 flex items-center justify-between gap-2">
        <a href="{{ route('month', ['mesiac' => $prev]) }}" class="btn btn-lg h-12 min-w-12 border-0 bg-base-100 text-lg font-bold shadow-sm" aria-label="Predchádzajúci mesiac">‹</a>
        <h1 class="text-center text-2xl font-bold capitalize">{{ SlovakDate::monthYear($month) }}</h1>
        <a href="{{ route('month', ['mesiac' => $next]) }}" class="btn btn-lg h-12 min-w-12 border-0 bg-base-100 text-lg font-bold shadow-sm" aria-label="Nasledujúci mesiac">›</a>
    </div>

    <div class="card mb-4 bg-primary p-4 text-primary-content shadow-sm">
        <div class="flex items-baseline justify-between">
            <span class="text-lg font-semibold">Spolu</span>
            <span class="text-3xl font-bold">{{ Money::format($totalCents) }}</span>
        </div>
        <div class="mt-1 text-base">
            {{ $orders->count() }} {{ $orders->count() === 1 ? 'jedlo' : ($orders->count() >= 2 && $orders->count() <= 4 ? 'jedlá' : 'jedál') }} z aplikácie
            @foreach ($byType as $name => $t)
                · {{ $name }}: {{ $t['count'] }} ks ({{ Money::format($t['cents']) }})
            @endforeach
        </div>
        @if ($isCurrent)
            <div class="mt-1 text-sm">Vrátane objednávok na zvyšok mesiaca.</div>
        @endif
        @if ($mandatoryCount)
            <div class="mt-2 border-t border-primary-line pt-2 text-base">
                <span class="font-semibold">Povinná strava:</span> {{ $mandatoryCount }} jedál ({{ Money::format($mandatoryCents) }})
                <div class="text-sm">Účtuje sa automaticky podľa dochádzky{{ $mandatoryFinal ? ' – konečný stav.' : ' – odhad podľa plánu smien a dochádzky.' }}</div>
            </div>
        @endif
        @if ($tickets && $byOrders)
            <div class="mt-2 border-t border-primary-line pt-2 text-base font-semibold">Cena sa potvrdí podľa dochádzky na konci mesiaca.</div>
        @elseif ($tickets)
            <div class="mt-2 border-t border-primary-line pt-2 text-base">
                <span class="font-semibold">Lístky:</span>
                pridelené {{ $tickets['allocated'] }} · použité {{ min($tickets['used'], $tickets['allocated']) }} ·
                @if ($ended) prepadnuté {{ $tickets['left'] }} @else zostáva {{ $tickets['left'] }} @endif
                @if ($tickets['over']) · nad počet lístkov {{ $tickets['over'] }} (dokupovaná strava) @endif
                @unless ($ended)<div class="text-sm">Nevyužité lístky na konci mesiaca prepadnú.</div>@endunless
            </div>
        @endif
        <div class="mt-2 border-t border-primary-line pt-2 text-sm font-semibold">
            @if ($closed)
                {{ Money::CLOSED_NOTE }}
            @else
                {{ Money::ESTIMATE_DISCLAIMER }} @if ($tickets) {{ Money::TICKETS_NOTE }} @endif
            @endif
        </div>
    </div>

    @forelse ($dates as $ymd)
        @php $dayOrders = $byDay[$ymd] ?? collect(); $m = $mandatory[$ymd] ?? null; $d = \App\Support\LocalTime::date($ymd); @endphp
        <div class="card mb-2 bg-base-100 p-3 shadow-sm">
            <div class="mb-1 text-base font-bold"><span class="capitalize">{{ SlovakDate::day($d) }}</span> {{ SlovakDate::dm($d) }}</div>
            <ul class="divide-y divide-base-200">
                @if ($m)
                    <li class="flex items-start justify-between gap-3 py-1.5">
                        <span class="text-base"><span class="font-semibold">Povinná strava</span> × {{ $m->count }} <span class="text-sm text-neutral">(podľa dochádzky)</span></span>
                        <span class="whitespace-nowrap text-base font-semibold">{{ Money::format($m->count * $m->unit_price_cents) }}</span>
                    </li>
                @endif
                @foreach ($dayOrders as $o)
                    <li class="flex items-start justify-between gap-3 py-1.5">
                        <span class="text-base">
                            <span class="font-semibold">{{ $o->mealSlot->name }}</span> –
                            {{ $o->menuItem->label() }}: {{ $o->menuItem->name }}
                            @if ($o->priceType->isExtra())
                                <span class="badge badge-accent ml-1 h-auto px-1.5 text-sm font-bold">{{ $o->priceType->name }}</span>
                            @endif
                        </span>
                        <span class="whitespace-nowrap text-base font-semibold">{{ Money::format($o->unit_price_cents) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="card bg-base-100 p-6 text-center text-lg text-neutral shadow-sm">V tomto mesiaci nemáte žiadne objednávky.</p>
    @endforelse
</div>
@endsection
