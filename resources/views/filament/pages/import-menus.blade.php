@php
    use App\Domain\Menus\MenuWriter;
    use App\Support\LocalTime;
    use App\Support\SlovakDate;
    $slotNames = \App\Models\MealSlot::query()->pluck('name', 'id');
    $labels = [MenuWriter::CREATE => 'nové', MenuWriter::UPDATE => 'zmena', MenuWriter::UNCHANGED => 'bez zmeny', MenuWriter::REMOVE => 'odstránené'];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. Súbor</x-slot>
        <x-slot name="description">
            XLSX alebo CSV, <strong>jeden riadok na deň</strong> so stĺpcami <code>datum</code> (napr. 16.11.2026), <code>polievka</code>, <code>obed_1</code>, <code>obed_2</code>, <code>vecera</code>.
            Alergény píšte za jedlo do zátvorky, napr. <em>Hovädzí guláš, knedľa (1,3,7)</em>, alebo do stĺpca <code>obed_1_alergeny</code> a pod.
            Prázdna bunka nič nemení. Existujúce jedlá v rovnaký deň a s rovnakým číslom sa prepíšu.
            (Funguje aj starší formát <code>datum, jedlo, cislo_menu, nazov, alergeny</code>.)
            <a href="{{ route('admin.template', 'menu') }}" class="font-semibold text-primary-600 underline">Stiahnuť šablónu</a>
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
            $plan = collect($preview['plan']);
            $counts = $plan->countBy('action');
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

            @if ($preview['impact'])
                <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:bg-warning-950">
                    <p class="mb-1 font-semibold text-warning-700">Pozor: meníte jedlá, ktoré už majú objednávky</p>
                    @foreach ($preview['impact'] as $what => $names)
                        <p><strong>{{ $what }}</strong>: {{ implode(', ', $names) }}</p>
                    @endforeach
                </div>
            @endif

            <div class="max-h-[28rem] overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                        <tr><th class="px-3 py-2">Dátum</th><th class="px-3 py-2">Jedlo</th><th class="px-3 py-2">Menu</th><th class="px-3 py-2">Názov</th><th class="px-3 py-2">Alergény</th><th class="px-3 py-2">Výsledok</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($plan->sortBy([['date', 'asc'], ['meal_slot_id', 'asc'], ['number', 'asc']]) as $r)
                            @php $d = LocalTime::date($r['date']); @endphp
                            <tr>
                                <td class="px-3 py-1.5">{{ SlovakDate::dayShort($d) }} {{ $d->format('j. n. Y') }}</td>
                                <td class="px-3 py-1.5">{{ $slotNames[$r['meal_slot_id']] ?? '?' }}</td>
                                <td class="px-3 py-1.5">{{ ($r['kind'] ?? 'item') === 'soup' ? 'polievka' : $r['number'] }}</td>
                                <td class="px-3 py-1.5">
                                    {{ $r['name'] }}
                                    @if ($r['action'] === 'update' && $r['old_name'] !== $r['name'])
                                        <span class="block text-xs text-gray-500">predtým: {{ $r['old_name'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5">{{ $r['allergens'] }}</td>
                                <td class="px-3 py-1.5">
                                    <span @class(['rounded px-1.5 py-0.5 text-xs font-semibold',
                                        'bg-success-100 text-success-800' => $r['action'] === 'create',
                                        'bg-warning-100 text-warning-800' => $r['action'] === 'update',
                                        'bg-gray-100 text-gray-600' => $r['action'] === 'unchanged'])>{{ $labels[$r['action']] ?? $r['action'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex gap-3">
                <x-filament::button wire:click="commit" wire:loading.attr="disabled" :disabled="$plan->whereIn('action', ['create', 'update'])->isEmpty()">Uložiť jedálny lístok</x-filament::button>
                <x-filament::button color="gray" wire:click="resetImport">Zrušiť</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
