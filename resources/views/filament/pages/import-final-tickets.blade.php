<x-filament-panels::page>
    @php $field = 'rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800'; @endphp

    <x-filament::section>
        <x-slot name="heading">1. Mesiac a súbor</x-slot>
        <x-slot name="description">
            Konečný počet lístkov na zamestnanca podľa dochádzky. XLSX alebo CSV so stĺpcami <code>osobne_cislo</code> a <code>listky</code>
            (ostatné stĺpce, napr. <code>priezvisko</code>, <code>meno</code>, sa ignorujú). Oddeľovač <code>;</code>, <code>,</code> alebo tabulátor, kódovanie UTF-8 alebo Windows-1250.
            Počty sa uložia ako individuálne lístky zamestnancov na zvolený mesiac a ceny sa prepočítajú.
            <a href="{{ route('admin.template', 'konecne-listky') }}" class="font-semibold text-primary-600 underline">Stiahnuť šablónu</a>
        </x-slot>

        <form wire:submit="analyze" class="flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm">
                <span class="font-semibold">Mesiac</span>
                <input type="month" wire:model.live="month" required class="{{ $field }}">
            </label>
            <input type="file" wire:model="file" accept=".xlsx,.csv" class="text-sm">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,analyze">Načítať a skontrolovať</x-filament::button>
            <span wire:loading wire:target="file" class="text-sm text-gray-500">Nahrávam…</span>
        </form>
        @error('month') <p class="mt-2 text-sm font-semibold text-danger-600">{{ $message }}</p> @enderror
        @error('file') <p class="mt-2 text-sm font-semibold text-danger-600">{{ $message }}</p> @enderror
    </x-filament::section>

    @if ($saved)
        <x-filament::section>
            <x-slot name="heading">Uložené – uzavrieť mesiac?</x-slot>
            <x-slot name="description">Po uzavretí sa v mesiaci {{ \App\Domain\Tickets\Month::label($saved) }} nedajú meniť objednávky ani lístky a exporty budú mať konečné ceny. Dá sa znova otvoriť s dôvodom.</x-slot>
            <div class="flex gap-3">
                <x-filament::button color="danger" icon="heroicon-m-lock-closed" wire:click="closeMonth" wire:confirm="Uzavrieť mesiac {{ \App\Domain\Tickets\Month::label($saved) }}?">Uzavrieť mesiac</x-filament::button>
                <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\TicketMonth::getUrl(['mesiac' => $saved])">Skontrolovať v Lístkoch na mesiac</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($preview)
        @php $rows = collect($preview['rows']); @endphp
        <x-filament::section>
            <x-slot name="heading">2. Kontrola – {{ $label }}</x-slot>
            <x-slot name="description">
                Zamestnancov v súbore: <strong>{{ $rows->count() }}</strong> ·
                zmení sa cena <strong>{{ $preview['totals']['orders'] }}</strong> jedál ·
                spolu {{ \App\Support\Money::format($preview['totals']['before']) }} → <strong>{{ \App\Support\Money::format($preview['totals']['after']) }}</strong> ·
                chýb: <strong @class(['text-danger-600' => count($preview['errors'])])>{{ count($preview['errors']) }}</strong> (riadky s chybou sa preskočia)
            </x-slot>

            @if ($preview['errors'])
                <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:bg-danger-950">
                    <p class="mb-1 font-semibold text-danger-700">Chyby</p>
                    <ul class="list-disc pl-5">
                        @foreach ($preview['errors'] as $e)
                            <li>Riadok {{ $e['line'] }}: {{ implode(' ', $e['messages']) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($preview['missing'])
                <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:bg-warning-950">
                    <p class="mb-1 font-semibold">V súbore chýbajú {{ count($preview['missing']) }} zamestnanci v režime lístkov – ponechajú si súčasný počet:</p>
                    <p>{{ collect($preview['missing'])->map(fn ($m) => $m['name'].' ('.$m['osobne_cislo'].', '.$m['allocated'].')')->join(', ') }}</p>
                </div>
            @endif

            <div class="max-h-[28rem] overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2">Riadok</th><th class="px-3 py-2">Os. číslo</th><th class="px-3 py-2">Meno</th>
                            <th class="px-3 py-2 text-right">Lístky</th><th class="px-3 py-2 text-right">Jedál</th>
                            <th class="px-3 py-2 text-right">Zmena ceny</th><th class="px-3 py-2 text-right">Suma</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows->sortByDesc('changed') as $r)
                            <tr>
                                <td class="px-3 py-1.5 text-gray-500">{{ $r['line'] }}</td>
                                <td class="px-3 py-1.5">{{ $r['osobne_cislo'] }}</td>
                                <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                                <td class="px-3 py-1.5 text-right">@if ($r['old'] !== $r['new']){{ $r['old'] }} → @endif<strong>{{ $r['new'] }}</strong></td>
                                <td class="px-3 py-1.5 text-right">{{ $r['used'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $r['changed'] ?: '–' }}</td>
                                <td class="px-3 py-1.5 text-right">@if ($r['before'] !== $r['after']){{ \App\Support\Money::format($r['before']) }} → <strong>{{ \App\Support\Money::format($r['after']) }}</strong>@else{{ \App\Support\Money::format($r['before']) }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex gap-3">
                <x-filament::button wire:click="saveImport" wire:loading.attr="disabled" :disabled="$rows->isEmpty()">Uložiť a prepočítať</x-filament::button>
                <x-filament::button color="gray" wire:click="resetImport">Zrušiť</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
