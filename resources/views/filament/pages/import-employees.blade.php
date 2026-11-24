<x-filament-panels::page>
    @php
        $labels = ['create' => 'nový', 'update' => 'zmena', 'unchanged' => 'bez zmeny'];
        $fields = ['last_name' => 'priezvisko', 'first_name' => 'meno', 'department' => 'stredisko', 'stredisko_cislo' => 'č. strediska', 'is_active' => 'aktívny', 'group' => 'skupina', 'vema_cislo' => 'Vema č.', 'attendance_cislo' => 'č. v dochádzke', 'max_meals_per_day' => 'max. jedál/deň', 'billing_name' => 'fakt. názov', 'ico' => 'IČO', 'dic' => 'DIČ', 'billing_address' => 'adresa', 'billing_email' => 'e-mail', 'billing_note' => 'fakt. poznámka'];
        $fmt = fn ($v) => is_bool($v) ? ($v ? 'áno' : 'nie') : ($v ?? '–');
    @endphp

    <x-filament::section>
        <x-slot name="heading">1. Súbor</x-slot>
        <x-slot name="description">
            <strong>Hlavný zoznam:</strong> XLSX/CSV so stĺpcami <code>Vema</code>, <code>Name</code> (Priezvisko Meno), <code>Workplace no.</code>, <code>Workplace</code>, <code>Dochádzka</code> (napr. 181-1), <code>Name in attendance</code> (len kontrola) a <code>Forma stravy</code> (názov skupiny alebo jej „Názvy v importe“; prázdne = bez zmeny). Ak súbor nemá stĺpec osobne_cislo, <strong>osobné číslo = Vema číslo</strong>. Ďalšie stĺpce (napr. „#“) sa ignorujú. Súbor môžete nahrať kedykoľvek znova.<br>
            Funguje aj pôvodný formát: XLSX alebo CSV so stĺpcami <code>osobne_cislo</code>, <code>priezvisko</code>, <code>meno</code>, <code>stredisko</code>, <code>stredisko_cislo</code>, <code>aktivny</code> (voliteľný), <code>skupina</code> (voliteľný – názov existujúcej skupiny stravníkov; kto ešte nemá skupinu, dostane ju od dnes, zmena z inej skupiny platí od 1. dňa budúceho mesiaca, prázdne = bez zmeny), <code>vema_cislo</code>, <code>cislo_dochadzka</code> (ak sa číslo vo WebVisitore líši od osobného) a <code>max_jedal_za_den</code> (1, 2 alebo „-“ = bez obmedzenia; voliteľné), pre SZČO <code>fakturacny_nazov</code>, <code>ico</code>, <code>dic</code>, <code>adresa</code>, <code>email</code>, <code>fakturacna_poznamka</code>.
            <strong>Párovanie je podľa osobného čísla</strong> – ak sa niekomu zmenilo, zmeňte ho najprv v karte zamestnanca, inak import vytvorí nového človeka.
            Zamestnanci sa párujú podľa osobného čísla; nikto sa nemaže.
            <a href="{{ route('admin.template', 'zamestnanci') }}" class="font-semibold text-primary-600 underline">Stiahnuť šablónu</a>
        </x-slot>

        <form wire:submit="analyze" class="flex flex-wrap items-center gap-3">
            <input type="file" wire:model="file" accept=".xlsx,.csv" class="text-sm">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,analyze">Načítať a skontrolovať</x-filament::button>
            <span wire:loading wire:target="file" class="text-sm text-gray-500">Nahrávam…</span>
        </form>
        @error('file') <p class="mt-2 text-sm font-semibold text-danger-600">{{ $message }}</p> @enderror
    </x-filament::section>

    @if ($preview)
        @php
            $rows = collect($preview['rows']);
            $counts = $rows->countBy('action');
        @endphp

        <x-filament::section>
            <x-slot name="heading">2. Kontrola</x-slot>
            <x-slot name="description">
                Nových: <strong>{{ $counts['create'] ?? 0 }}</strong> ·
                zmien: <strong>{{ $counts['update'] ?? 0 }}</strong> ·
                bez zmeny: <strong>{{ $counts['unchanged'] ?? 0 }}</strong> ·
                chýb: <strong @class(['text-danger-600' => count($preview['errors'])])>{{ count($preview['errors']) }}</strong>
                (riadky s chybou sa preskočia)
            </x-slot>

            @if ($preview['key_from_vema'] ?? false)
                <p class="mb-3 text-sm font-semibold">Osobné číslo = Vema číslo (súbor nemá stĺpec osobne_cislo; Vema číslo je aj prihlasovacie číslo).</p>
            @endif

            @if ($preview['group_counts'] ?? [])
                <div class="mb-4 text-sm">
                    <p class="font-semibold">Skupiny stravníkov po importe (zamestnanci zo súboru)</p>
                    <p class="text-gray-600">
                        @foreach ($preview['group_counts'] as $name => $n)
                            {{ $name }}: <strong>{{ $n }}</strong>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                </div>
            @endif

            @if ($preview['unset'] ?? [])
                <div class="mb-4 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm dark:border-white/10 dark:bg-gray-800">
                    <p class="font-semibold">Nenastavená forma stravy ({{ count($preview['unset']) }})</p>
                    <p class="text-gray-600">Uložia sa; kým nedostanú skupinu, sú v dennom režime a môžu si objednávať. Skupinu im neskôr priradíte v zozname zamestnancov (filter „Bez skupiny“ → „Priradiť skupinu“) alebo doplnením stĺpca Forma stravy.</p>
                    <p class="mt-1">{{ collect($preview['unset'])->map(fn ($u) => $u['name'].' ('.$u['osobne_cislo'].')')->join(', ') }}</p>
                </div>
            @endif

            @if ($preview['warnings'] ?? [])
                <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:bg-warning-950">
                    <p class="mb-1 font-semibold">Upozornenia</p>
                    <ul class="list-disc pl-5">
                        @foreach ($preview['warnings'] as $w)
                            <li>Riadok {{ $w['line'] }}: {{ $w['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

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

            <div class="max-h-[28rem] overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                        <tr><th class="px-3 py-2">Riadok</th><th class="px-3 py-2">{{ ($preview['key_from_vema'] ?? false) ? 'Os. č. (Vema)' : 'Os. číslo' }}</th><th class="px-3 py-2">Meno</th><th class="px-3 py-2">Výsledok</th><th class="px-3 py-2">Zmeny</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows->where('action', '!=', 'unchanged')->concat($rows->where('action', 'unchanged')) as $r)
                            <tr>
                                <td class="px-3 py-1.5 text-gray-500">{{ $r['line'] }}</td>
                                <td class="px-3 py-1.5">{{ $r['osobne_cislo'] }}</td>
                                <td class="px-3 py-1.5">
                                    {{ $r['name'] }}
                                    @if ($r['split'] ?? null)
                                        <span class="block text-xs text-gray-500">priezvisko: {{ $r['split']['last_name'] }} · meno: {{ $r['split']['first_name'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5">
                                    <span @class(['rounded px-1.5 py-0.5 text-xs font-semibold',
                                        'bg-success-100 text-success-800' => $r['action'] === 'create',
                                        'bg-warning-100 text-warning-800' => $r['action'] === 'update',
                                        'bg-gray-100 text-gray-600' => $r['action'] === 'unchanged'])>{{ $labels[$r['action']] }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-gray-600">
                                    @foreach ($r['changes'] as $f => $c)
                                        {{ $fields[$f] }}: {{ $fmt($c['old']) }} → <strong>{{ $fmt($c['new']) }}</strong>@if ($f === 'group' && ($r['group'] ?? null)) (od {{ $this->groupFromLabel($r['group']['from']) }})@endif @if (! $loop->last), @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($rows->whereNotNull('group')->isNotEmpty())
                <div class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                    <label class="font-semibold" for="groupFrom">Skupina platí od</label>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <input id="groupFrom" type="date" wire:model.live="groupFrom" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                        @if (filled($groupFrom))
                            <x-filament::link tag="button" wire:click="clearGroupFrom">Späť na predvolené</x-filament::link>
                        @endif
                    </div>
                    <p class="mt-1 text-gray-600">Prázdne = predvolené: kto ešte nemá skupinu, od dnes ({{ \App\Support\LocalTime::date($preview['today'])->format('j. n. Y') }}); zmena z inej skupiny od {{ \App\Support\LocalTime::date($preview['next_month'])->format('j. n. Y') }}. Zadaný dátum platí pre všetky zmeny skupiny v súbore (aj pre nových zamestnancov); už objednané jedlá od tohto dňa sa prepočítajú.</p>
                    @error('groupFrom') <p class="mt-1 font-semibold text-danger-600">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($preview['missing'])
                <div class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                    <label class="flex items-center gap-2 font-semibold">
                        <input type="checkbox" wire:model="deactivateMissing" class="rounded">
                        Deaktivovať {{ count($preview['missing']) }} aktívnych zamestnancov, ktorí v súbore nie sú
                    </label>
                    <p class="mt-1 text-gray-600">{{ collect($preview['missing'])->map(fn ($m) => $m['name'].' ('.$m['osobne_cislo'].')')->join(', ') }}</p>
                </div>
            @endif

            <div class="mt-4 flex gap-3">
                <x-filament::button wire:click="saveImport" wire:loading.attr="disabled" :disabled="$rows->whereIn('action', ['create', 'update'])->isEmpty() && ! $preview['missing']">Uložiť zmeny</x-filament::button>
                <x-filament::button color="gray" wire:click="resetImport">Zrušiť</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
