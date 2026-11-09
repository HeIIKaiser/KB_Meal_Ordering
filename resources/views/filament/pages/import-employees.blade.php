<x-filament-panels::page>
    @php
        $labels = ['create' => 'nový', 'update' => 'zmena', 'unchanged' => 'bez zmeny'];
        $fields = ['last_name' => 'priezvisko', 'first_name' => 'meno', 'department' => 'stredisko', 'is_active' => 'aktívny'];
        $fmt = fn ($v) => is_bool($v) ? ($v ? 'áno' : 'nie') : ($v ?? '–');
    @endphp

    <x-filament::section>
        <x-slot name="heading">1. Súbor</x-slot>
        <x-slot name="description">
            XLSX alebo CSV so stĺpcami <code>osobne_cislo</code>, <code>priezvisko</code>, <code>meno</code>, <code>stredisko</code>, <code>aktivny</code> (voliteľný).
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
                        <tr><th class="px-3 py-2">Riadok</th><th class="px-3 py-2">Os. číslo</th><th class="px-3 py-2">Meno</th><th class="px-3 py-2">Výsledok</th><th class="px-3 py-2">Zmeny</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows->where('action', '!=', 'unchanged')->concat($rows->where('action', 'unchanged')) as $r)
                            <tr>
                                <td class="px-3 py-1.5 text-gray-500">{{ $r['line'] }}</td>
                                <td class="px-3 py-1.5">{{ $r['osobne_cislo'] }}</td>
                                <td class="px-3 py-1.5">{{ $r['name'] }}</td>
                                <td class="px-3 py-1.5">
                                    <span @class(['rounded px-1.5 py-0.5 text-xs font-semibold',
                                        'bg-success-100 text-success-800' => $r['action'] === 'create',
                                        'bg-warning-100 text-warning-800' => $r['action'] === 'update',
                                        'bg-gray-100 text-gray-600' => $r['action'] === 'unchanged'])>{{ $labels[$r['action']] }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-gray-600">
                                    @foreach ($r['changes'] as $f => $c)
                                        {{ $fields[$f] }}: {{ $fmt($c['old']) }} → <strong>{{ $fmt($c['new']) }}</strong>@if (! $loop->last), @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

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
