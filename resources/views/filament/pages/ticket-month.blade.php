<x-filament-panels::page>
    @php $field = 'w-24 rounded-lg border border-gray-300 px-2 py-1 text-right text-sm disabled:bg-gray-100 disabled:text-gray-500 dark:border-white/20 dark:bg-gray-800'; @endphp

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button color="gray" size="sm" wire:click="previousMonth" icon="heroicon-m-chevron-left">Predchádzajúci</x-filament::button>
        <input type="month" wire:model.live="month" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800">
        <x-filament::button color="gray" size="sm" wire:click="nextMonth" icon="heroicon-m-chevron-right" icon-position="after">Nasledujúci</x-filament::button>
        <span class="text-lg font-semibold capitalize">{{ $label }}</span>
        @if ($closed)
            <x-filament::badge color="danger" icon="heroicon-m-lock-closed">Uzavretý {{ \App\Support\LocalTime::toLocal($closed->closed_at)->format('j. n. Y H:i') }}@if ($closed->closedBy) ({{ $closed->closedBy->name }})@endif</x-filament::badge>
        @else
            <x-filament::badge color="success">Otvorený</x-filament::badge>
        @endif
    </div>

    @if ($groups->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-600">V tomto mesiaci nie je žiadna skupina v režime lístkov. Všetci zamestnanci sú v dennom režime (prvý obed a prvá večera dňa za zamestnaneckú cenu).
                Skupiny a ich režim nastavíte v časti <a class="font-semibold text-primary-600 underline" href="{{ \App\Filament\Resources\MealGroups\MealGroupResource::getUrl() }}">Skupiny stravníkov</a>.
                Uzavrieť mesiac sa dá aj bez lístkov.</p>
        </x-filament::section>
    @else
        <form wire:submit="review" class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Lístky pre skupiny</x-slot>
                <x-slot name="description">Počet lístkov na zamestnanca v mesiaci. Predvyplnené z minulého mesiaca, inak z predvoleného počtu skupiny. Kým nič neuložíte, platí predvolený počet skupiny.</x-slot>
                <table class="w-full max-w-2xl text-left text-sm">
                    <thead><tr class="border-b border-gray-200 dark:border-white/10"><th class="py-2">Skupina</th><th class="py-2">Predvolené</th><th class="py-2">Uložené na mesiac</th><th class="py-2 text-right">Lístkov</th></tr></thead>
                    <tbody>
                        @foreach ($groups as $g)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 font-semibold">{{ $g->name }}</td>
                                <td class="py-2">{{ $g->default_tickets }}</td>
                                <td class="py-2">{{ $saved[$g->id] ?? 'nie (platí predvolené)' }}</td>
                                <td class="py-2 text-right"><input type="number" min="0" max="999" wire:model="counts.{{ $g->id }}" @disabled(! $editable) class="{{ $field }}" aria-label="Lístkov pre {{ $g->name }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @error('counts.*') <p class="mt-2 text-sm font-semibold text-danger-600">{{ $message }}</p> @enderror
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Zamestnanci v režime lístkov ({{ $rows->count() }})</x-slot>
                <x-slot name="description">Individuálny počet (napr. nástup alebo odchod počas mesiaca, dlhšia neprítomnosť). Prázdne = počet skupiny. Po uložení sa ceny objednávok v mesiaci hneď prepočítajú.</x-slot>
                <div class="max-h-[32rem] overflow-auto">{{-- scrolls inside on small screens --}}
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-white dark:bg-gray-900">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2">Zamestnanec</th><th class="py-2">Os. č.</th><th class="py-2">Skupina</th>
                                <th class="py-2 text-right">Pridelené</th><th class="py-2 text-right">Použité</th><th class="py-2 text-right">{{ $isPast ? 'Prepadnuté' : 'Zostáva' }}</th>
                                <th class="py-2 text-right">Individuálne</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5">{{ $r['employee']->fullNameReversed() }}@unless ($r['employee']->is_active) <span class="text-gray-500">(neaktívny)</span>@endunless</td>
                                    <td class="py-1.5">{{ $r['employee']->osobne_cislo }}</td>
                                    <td class="py-1.5">{{ $r['group'] }}</td>
                                    <td class="py-1.5 text-right">{{ $r['allocated'] }}@if ($r['source'] === 'import') <span class="text-xs text-gray-500" title="z importu konečných lístkov">(import)</span>@endif</td>
                                    <td class="py-1.5 text-right">{{ $r['used'] }}</td>
                                    <td @class(['py-1.5 text-right', 'font-semibold text-danger-600' => $r['left'] < 0])>{{ $r['left'] < 0 ? 'o '.abs($r['left']).' viac (6 €)' : $r['left'] }}</td>
                                    <td class="py-1.5 text-right"><input type="number" min="0" max="999" wire:model="overrides.{{ $r['employee']->id }}" @disabled(! $editable) placeholder="skupina" class="{{ $field }}" aria-label="Individuálny počet pre {{ $r['employee']->fullNameReversed() }}"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('overrides.*') <p class="mt-2 text-sm font-semibold text-danger-600">{{ $message }}</p> @enderror
            </x-filament::section>

            @if ($editable)
                @if ($pending)
                    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:bg-warning-950">
                        <p class="mb-3 font-semibold">{{ $pending['text'] }}</p>
                        <div class="flex gap-3">
                            <x-filament::button type="button" wire:click="confirmSave" color="warning">Uložiť a prepočítať</x-filament::button>
                            <x-filament::button type="button" wire:click="cancelPending" color="gray">Späť</x-filament::button>
                        </div>
                    </div>
                @else
                    <div><x-filament::button type="submit">Uložiť</x-filament::button></div>
                @endif
            @elseif ($closed)
                <p class="text-sm text-gray-600">Mesiac je uzavretý – počty sa nedajú meniť.</p>
            @endif
        </form>
    @endif
</x-filament-panels::page>
