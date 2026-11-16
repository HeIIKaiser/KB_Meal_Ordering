<x-filament-panels::page>
    @php
        $money = fn ($c) => \App\Support\Money::format($c);
        $dm = fn ($d) => \Carbon\CarbonImmutable::parse($d)->format('j. n.');
        $fmt = fn ($t) => $t ? \App\Support\LocalTime::toLocal($t)->format('j. n. Y H:i') : 'nikdy';
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <input type="month" wire:model.live="month" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800">
        <span class="text-lg font-semibold capitalize">{{ $label }}</span>
        <a class="text-sm font-semibold text-primary-600 underline" href="{{ \App\Filament\Pages\AttendanceStatus::getUrl(['mesiac' => $month]) }}">Stav dochádzky</a>
    </div>

    @if ($closed)
        <x-filament::section><p class="text-sm">Mesiac je uzavretý. Ak treba prepočítať, najprv ho otvorte (Lístky na mesiac → Otvoriť mesiac, s dôvodom).</p></x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">1. Čerstvá dochádzka</x-slot>
            <x-slot name="description">Finalizuje sa zvyčajne po 10. dni nasledujúceho mesiaca, keď vedúci dokončia opravy dochádzky.@if ($early) <strong class="text-warning-600">Je ešte pred 10. dňom – oddelenia možno ešte opravujú.</strong>@endif</x-slot>
            <div @if ($pending) wire:poll.5s @endif class="flex flex-wrap items-center gap-3 text-sm">
                @if ($pending)
                    <x-filament::loading-indicator class="h-5 w-5" /> <span>Čaká sa na most na DOCKER01 (do 5 minút)…</span>
                @else
                    <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="requestSync">Synchronizovať teraz</x-filament::button>
                @endif
                <span>Posledné dáta: <strong>{{ $fmt($last?->received_at) }}</strong>@if ($fresh) <span class="text-success-600">– čerstvé</span>@endif</span>
                <x-filament::button wire:click="makePreview" :color="$fresh ? 'primary' : 'gray'">
                    {{ $fresh ? 'Zobraziť náhľad' : 'Pokračovať s poslednými dátami' }}
                </x-filament::button>
            </div>
            @if (! $fresh && $preview !== null)
                <p class="mt-2 text-sm font-semibold text-warning-600">Náhľad je z dát z {{ $fmt($last?->received_at) }} – novšie opravy v dochádzke v ňom nie sú.</p>
            @endif
        </x-filament::section>
    @endif

    @if ($preview !== null)
        <x-filament::section>
            <x-slot name="heading">2. {{ $saved ? 'Uložené' : 'Náhľad' }}</x-slot>
            <x-slot name="description">
                Zamestnancov v režime lístkov / povinnej stravy: <strong>{{ $totals['employees'] }}</strong>, zmena oproti odhadu: <strong>{{ $totals['changed'] }}</strong>.
                Spolu {{ $money($totals['before']) }} → <strong>{{ $money($totals['after']) }}</strong>.
                Počíta sa len skutočná dochádzka; nevyplnený plánovaný deň = 0.
            </x-slot>
            <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="showAll" class="rounded"> Zobraziť všetkých (aj bez zmeny)</label>
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-gray-900"><tr class="border-b">
                        <th class="py-2">Zamestnanec</th><th class="py-2 text-right">Lístky odhad → konečné</th><th class="py-2 text-right">Povinná strava</th>
                        <th class="py-2 text-right">Jedál z app.</th><th class="py-2 text-right">Zmena ceny</th><th class="py-2 text-right">€ pred → po</th><th class="py-2">Upozornenia</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-1.5">{{ $r['name'] }} <span class="text-gray-500">({{ $r['osobne_cislo'] }})</span></td>
                                <td class="py-1.5 text-right">@if ($r['tickets']){{ $r['tickets'][0] ?? '–' }} → <strong>{{ $r['tickets'][1] ?? '–' }}</strong>@if ($r['allocation'] && $r['allocation']['source'] !== 'attendance_final')<br><span class="text-xs text-gray-500">platí {{ $r['allocation']['tickets'] }} (ručne)</span>@endif @else – @endif</td>
                                <td class="py-1.5 text-right">@if ($r['mandatory']){{ $r['mandatory'][0] }} → <strong>{{ $r['mandatory'][1] }}</strong>@else – @endif</td>
                                <td class="py-1.5 text-right">{{ $r['used'] }}</td>
                                <td class="py-1.5 text-right">{{ $r['repriced'] ?: '–' }}</td>
                                <td class="py-1.5 text-right">{{ $money($r['cents'][0]) }} → <strong>{{ $money($r['cents'][1]) }}</strong></td>
                                <td class="py-1.5 text-xs">
                                    @if ($r['flags']['unfilled'])<div class="text-warning-600">nevyplnené: {{ collect($r['flags']['unfilled'])->map($dm)->join(', ') }}</div>@endif
                                    @if ($r['flags']['not_locked'])<div class="text-warning-600">dochádzka neuzamknutá</div>@endif
                                    @if ($r['flags']['no_data'])<div class="text-danger-600">bez dochádzky</div>@endif
                                    @if ($r['flags']['manual_override'])<div>individuálny počet ponechaný</div>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-3 text-gray-600">Nikomu sa oproti odhadu nič nemení.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex gap-3">
                @if (! $saved)
                    <x-filament::button wire:click="save" wire:confirm="Uložiť konečné lístky a povinnú stravu a prepočítať ceny?">Uložiť</x-filament::button>
                @else
                    <x-filament::button color="danger" icon="heroicon-m-lock-closed" wire:click="closeMonth" wire:confirm="Uzavrieť mesiac {{ $label }}?">Uzavrieť mesiac</x-filament::button>
                    <span class="self-center text-sm text-gray-600">Potom: Objednávky → Exporty → export do miezd (Vema).</span>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
