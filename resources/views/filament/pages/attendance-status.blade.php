<x-filament-panels::page>
    @php
        $fmt = fn ($t) => $t ? \App\Support\LocalTime::toLocal($t)->format('j. n. Y H:i') : '–';
        $dm = fn ($d) => \Carbon\CarbonImmutable::parse($d)->format('j. n.');
    @endphp
    <div @if ($pending['pending']) wire:poll.10s @endif class="flex flex-wrap items-center gap-3">
        <x-filament::button color="gray" size="sm" wire:click="previousMonth" icon="heroicon-m-chevron-left">Predchádzajúci</x-filament::button>
        <input type="month" wire:model.live="month" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800">
        <x-filament::button color="gray" size="sm" wire:click="nextMonth" icon="heroicon-m-chevron-right" icon-position="after">Nasledujúci</x-filament::button>
        <span class="text-lg font-semibold capitalize">{{ $label }}</span>
        @if ($closed)<x-filament::badge color="danger" icon="heroicon-m-lock-closed">Uzavretý</x-filament::badge>@endif
        @if ($isAdmin)
            <span class="flex-1"></span>
            <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="requestSync" :disabled="$pending['pending']">
                {{ $pending['pending'] ? 'Čaká sa na most…' : 'Synchronizovať teraz' }}
            </x-filament::button>
            @unless ($closed)
                <x-filament::button tag="a" :href="\App\Filament\Pages\FinalizeMonth::getUrl(['mesiac' => $month])" icon="heroicon-m-check-badge">Finalizovať mesiac</x-filament::button>
            @endunless
        @endif
    </div>

    <x-filament::section>
        <x-slot name="heading">Synchronizácia</x-slot>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt class="font-semibold">Posledné dáta za {{ $label }}</dt>
            <dd>{{ $fmt($lastData?->received_at) }} @if ($lastData)({{ $lastData->employees }} ľudí, {{ $lastData->days }} dní)@endif</dd>
            <dt class="font-semibold">Posledná úspešná synchronizácia</dt>
            <dd @class(['font-semibold text-danger-600' => ! $lastAny || $lastAny->received_at->lt(now()->subHours(36))])>{{ $fmt($lastAny?->received_at) }}
                @if (! $lastAny || $lastAny->received_at->lt(now()->subHours(36))) – most sa neozval viac ako 36 h, skontrolujte DOCKER01 @endif</dd>
            @if ($lastError && (! $lastAny || $lastError->received_at->gt($lastAny->received_at)))
                <dt class="font-semibold text-danger-600">Posledná chyba</dt>
                <dd class="text-danger-600">{{ $fmt($lastError->received_at) }} – {{ $lastError->message }}</dd>
            @endif
            <dt class="font-semibold">Dochádzka uzamknutá oddeleniami</dt>
            <dd>u {{ $locked }} z {{ $withData }} zamestnancov (v režime lístkov / povinnej stravy)</dd>
        </dl>
    </x-filament::section>

    @if ($late)
        <x-filament::section>
            <x-slot name="heading"><span class="text-danger-600">Dochádzka sa zmenila po uzavretí</span></x-slot>
            <x-slot name="description">Ceny sa nezmenili. Ak treba, mesiac znova otvorte (Lístky na mesiac → Otvoriť mesiac, s dôvodom) a finalizujte, alebo to vyrovnajte v ďalšom mesiaci. Prijaté {{ $fmt($late->received_at) }}.</x-slot>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="py-1">Zamestnanec</th><th class="py-1">Lístky</th><th class="py-1">Povinná strava</th><th class="py-1 text-right">Rozdiel €</th></tr></thead>
                <tbody>
                    @foreach ($late->late_changes as $c)
                        <tr class="border-b border-gray-100"><td class="py-1">{{ $c['name'] }} ({{ $c['osobne_cislo'] }})</td>
                            <td class="py-1">{{ $c['tickets'][0] ?? '–' }} → {{ $c['tickets'][1] ?? '–' }}</td>
                            <td class="py-1">{{ $c['mandatory'][0] ?? '–' }} → {{ $c['mandatory'][1] ?? '–' }}</td>
                            <td class="py-1 text-right">{{ ($c['cents'] > 0 ? '+' : '').\App\Support\Money::format($c['cents']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    @if ($unmatched || $missing->isNotEmpty() || $unfilled->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Na kontrolu</x-slot>
            <div class="grid gap-4 text-sm">
                @if ($unmatched)
                    <div><p class="font-semibold">Nepárované z dochádzky ({{ count($unmatched) }})</p>
                        <p class="text-gray-600">Osobné čísla z WebVisitora, ktoré v aplikácii nie sú (alebo sú dvakrát): {{ implode(', ', $unmatched) }}</p></div>
                @endif
                @if ($missing->isNotEmpty())
                    <div><p class="font-semibold">Bez dochádzky ({{ $missing->count() }})</p>
                        <p class="text-gray-600">Zamestnanci v režime lístkov / povinnej stravy, ktorí v dochádzke chýbajú (platí pre nich počet skupiny): {{ $missing->map(fn ($r) => $r['employee']->fullNameReversed().' ('.$r['employee']->osobne_cislo.')')->join(', ') }}</p></div>
                @endif
                @if ($unfilled->isNotEmpty())
                    <div><p class="font-semibold">Nevyplnené plánované dni – zamestnancov: {{ $unfilled->count() }}</p>
                        <ul class="text-gray-600">
                            @foreach ($unfilled as $r)<li>{{ $r['employee']->fullNameReversed() }}: {{ collect($r['unfilled'])->map($dm)->join(', ') }}</li>@endforeach
                        </ul>
                        <p class="text-gray-500">Kým je mesiac otvorený, rátajú sa podľa plánu. Pri finalizácii sa rátajú 0 – overte ich s oddelením.</p></div>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Zamestnanci podľa dochádzky ({{ $rows->count() }})</x-slot>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-600">V tomto mesiaci nie je nikto v režime lístkov ani povinnej stravy.</p>
        @else
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-gray-900"><tr class="border-b">
                        <th class="py-2">Zamestnanec</th><th class="py-2">Režim</th>
                        <th class="py-2 text-right">Lístky odhad</th><th class="py-2 text-right">Lístky konečné</th><th class="py-2">Platí</th>
                        <th class="py-2 text-right">Povinná odhad</th><th class="py-2 text-right">Povinná konečná</th><th class="py-2">Dochádzka</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5">{{ $r['employee']->fullNameReversed() }} <span class="text-gray-500">({{ $r['employee']->osobne_cislo }})</span></td>
                                <td class="py-1.5">{{ $r['modes'] }}</td>
                                <td class="py-1.5 text-right">{{ $r['result']?->estimate_meals ?? '–' }}</td>
                                <td class="py-1.5 text-right">{{ $r['result']?->final_meals ?? '–' }}</td>
                                <td class="py-1.5">@if ($r['allocation']){{ $r['allocation']['tickets'] }} <span class="text-xs text-gray-500">({{ $sources[$r['allocation']['source']] }})</span>@else – @endif</td>
                                <td class="py-1.5 text-right">{{ $r['result']?->mandatory_estimate ?? '–' }}</td>
                                <td class="py-1.5 text-right">{{ $r['result']?->mandatory_final ?? '–' }}</td>
                                <td class="py-1.5">{{ ! $r['has_data'] ? 'chýba' : ($r['locked'] ? 'uzamknutá' : 'otvorená') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
