<x-filament-panels::page>
    @php $field = 'rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800'; @endphp

    <x-filament::section>
        <x-slot name="heading">Mesačný prehľad (pre mzdy a dochádzku)</x-slot>
        <x-slot name="description">
            Excel s dvoma hárkami: <strong>Mesiac</strong> – pre každého zamestnanca počty jedál po dňoch (zamestnanecká / dokupovaná strava, víkendy podfarbené), spolu a suma;
            <strong>Súhrn</strong> – jeden riadok na zamestnanca (osobné číslo, meno, kusy, sumy) na import do miezd.
            Sumy sú podľa uložených cien (denný režim: v čase objednania, režim lístkov: podľa počtu lístkov); zrušené objednávky sa nepočítajú.
            V hárku Súhrn sú aj stĺpce Skupina, Režim (denny / listky / zmiesany) a lístky pridelené / použité / prepadnuté.
            <br><strong class="text-danger-600">{{ \App\Support\Money::ESTIMATE_DISCLAIMER }}</strong> Pre uzavretý mesiac je v exporte „{{ \App\Support\Money::CLOSED_NOTE }}“
            Aplikácia počíta ako zamestnaneckú stravu (1,50 €) prvý obed aj prvú večeru dňa; nárok podľa dĺžky smeny (8 h = 1 jedlo, 12 h = 2 jedlá) sa overí v dochádzke.
        </x-slot>

        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-semibold">Stav mesiacov:</span>
            @foreach ($months as $m)
                <x-filament::badge :color="$m['closed'] ? 'success' : 'gray'" :icon="$m['closed'] ? 'heroicon-m-lock-closed' : null">
                    {{ $m['label'] }} – {{ $m['closed'] ? 'uzavretý (konečné ceny)' : 'otvorený (odhad)' }}
                </x-filament::badge>
            @endforeach
            <a class="font-semibold text-primary-600 underline" href="{{ \App\Filament\Pages\TicketMonth::getUrl() }}">Lístky a uzávierka mesiaca</a>
        </div>

        <form method="GET" action="{{ route('exports.monthly') }}" class="flex flex-wrap items-end gap-4">
            <label class="grid gap-1 text-sm">
                <span class="font-semibold">Mesiac</span>
                <input type="month" name="mesiac" value="{{ $defaultMonth }}" required class="{{ $field }}">
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="po_jedlach" value="1" class="rounded"> Rozdeliť na obed / večeru
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="vsetci" value="1" class="rounded"> Aj aktívni zamestnanci bez objednávok
            </label>
            <x-filament::button type="submit" icon="heroicon-m-arrow-down-tray">Stiahnuť Excel</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Zoznam objednávok</x-slot>
        <x-slot name="description">
            Jeden riadok na objednávku (aj zrušené – stĺpec <code>stav</code>) za ľubovoľné obdobie, napr. na kontingenčnú tabuľku.
            Stĺpce: datum, jedlo, osobne_cislo, priezvisko, meno, stredisko, menu_cislo, menu_nazov, typ, cena, stav, vytvorene, zrusene, vytvoril, rezim (denny / listky).
        </x-slot>

        <form method="GET" action="{{ route('exports.list') }}" class="flex flex-wrap items-end gap-4">
            <label class="grid gap-1 text-sm">
                <span class="font-semibold">Od</span>
                <input type="date" name="od" value="{{ $from }}" required class="{{ $field }}">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-semibold">Do</span>
                <input type="date" name="do" value="{{ $to }}" required class="{{ $field }}">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-semibold">Formát</span>
                <select name="format" class="{{ $field }}">
                    <option value="xlsx">Excel (XLSX)</option>
                    <option value="csv">CSV (oddeľovač ;)</option>
                </select>
            </label>
            <x-filament::button type="submit" icon="heroicon-m-arrow-down-tray">Stiahnuť</x-filament::button>
        </form>
        @if ($errors->any())
            <p class="mt-3 text-sm font-semibold text-danger-600">{{ $errors->first() }}</p>
        @endif
    </x-filament::section>

    <p class="text-sm text-gray-500">Denný zoznam pre kuchyňu (tlač, PDF, Excel) je v časti <a class="font-semibold text-primary-600 underline" href="{{ \App\Filament\Pages\KitchenDay::getUrl() }}">Denný prehľad</a>. Každý export sa zapíše do histórie zmien.</p>
</x-filament-panels::page>
