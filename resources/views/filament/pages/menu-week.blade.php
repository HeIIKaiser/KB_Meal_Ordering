@php use App\Support\SlovakDate; @endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::button color="gray" icon="heroicon-m-chevron-left" wire:click="previousWeek">Predchádzajúci</x-filament::button>
        <x-filament::button color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="nextWeek">Nasledujúci</x-filament::button>
        <x-filament::button color="gray" icon="heroicon-m-document-duplicate" wire:click="copyPreviousWeek">Doplniť z minulého týždňa</x-filament::button>
        <x-filament::button tag="a" color="gray" icon="heroicon-m-arrow-up-tray" :href="\App\Filament\Pages\ImportMenus::getUrl()">Import zo súboru</x-filament::button>
        <x-filament::button tag="a" color="gray" icon="heroicon-m-printer" target="_blank" :href="route('menu.week.print', ['tyzden' => $week, 'tlac' => 1])">Tlačiť týždeň</x-filament::button>
        <x-filament::button tag="a" color="gray" icon="heroicon-m-document" target="_blank" :href="route('menu.week.pdf', ['tyzden' => $week])">PDF</x-filament::button>
        <span class="text-sm text-gray-500">P = polievka (podáva sa ku každému obedu, neobjednáva sa). Prázdny názov = jedlo sa odstráni. Alergény: čísla 1 – 14 oddelené čiarkou.</span>
    </div>

    <form wire:submit="save" class="space-y-3">
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="w-28 px-3 py-2 text-left">Deň</th>
                        @foreach ($mealSlots as $slot)
                            <th class="px-3 py-2 text-left">{{ $slot->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($days as $day)
                        <tr @class(['bg-gray-50/60 dark:bg-white/5' => $day->isWeekend()])>
                            <td class="px-3 py-2 align-top font-semibold">
                                {{ SlovakDate::dayShort($day) }} {{ SlovakDate::dm($day) }}
                            </td>
                            @foreach ($mealSlots as $slot)
                                @php $key = $day->toDateString().'|'.$slot->id; @endphp
                                <td class="px-3 py-2 align-top" wire:key="cell-{{ $key }}">
                                    <div class="space-y-1.5">
                                        @if (isset($soups[$key]))
                                            <div class="flex items-start gap-1.5">
                                                <span class="mt-2 w-5 shrink-0 text-right text-xs font-semibold text-warning-600" title="Polievka">P</span>
                                                <input type="text" wire:model="soups.{{ $key }}.name" placeholder="Polievka"
                                                       aria-label="{{ SlovakDate::dayShort($day) }} {{ $slot->name }} polievka"
                                                       @class(['flex-1 rounded-md border px-2 py-1.5 text-sm italic dark:bg-gray-800', 'border-danger-500' => $errors->has("soups.$key.name"), 'border-warning-300 dark:border-warning-700' => ! $errors->has("soups.$key.name")])>
                                                <input type="text" wire:model="soups.{{ $key }}.allergens" placeholder="alerg." aria-label="alergény polievky"
                                                       title="{{ $errors->first("soups.$key.allergens") }}"
                                                       @class(['w-20 rounded-md border px-2 py-1.5 text-sm dark:bg-gray-800', 'border-danger-500' => $errors->has("soups.$key.allergens"), 'border-warning-300 dark:border-warning-700' => ! $errors->has("soups.$key.allergens")])>
                                            </div>
                                        @endif
                                        @foreach ($grid[$key] ?? [] as $i => $row)
                                            <div class="flex items-start gap-1.5" wire:key="row-{{ $key }}-{{ $row['number'] }}">
                                                <span class="mt-2 w-5 shrink-0 text-right text-xs font-semibold text-gray-500">{{ $row['number'] }}</span>
                                                <div class="flex-1">
                                                    <input type="text" wire:model="grid.{{ $key }}.{{ $i }}.name" placeholder="Menu {{ $row['number'] }}"
                                                           aria-label="{{ SlovakDate::dayShort($day) }} {{ $slot->name }} menu {{ $row['number'] }} názov"
                                                           @class(['w-full rounded-md border px-2 py-1.5 text-sm dark:bg-gray-800', 'border-danger-500' => $errors->has("grid.$key.$i.name"), 'border-gray-300 dark:border-white/20' => ! $errors->has("grid.$key.$i.name")])>
                                                </div>
                                                <input type="text" wire:model="grid.{{ $key }}.{{ $i }}.allergens" placeholder="alerg."
                                                       aria-label="alergény"
                                                       title="{{ $errors->first("grid.$key.$i.allergens") }}"
                                                       @class(['w-20 rounded-md border px-2 py-1.5 text-sm dark:bg-gray-800', 'border-danger-500' => $errors->has("grid.$key.$i.allergens"), 'border-gray-300 dark:border-white/20' => ! $errors->has("grid.$key.$i.allergens")])>
                                            </div>
                                        @endforeach
                                        <button type="button" wire:click="addRow('{{ $key }}')" class="ml-6 text-xs font-semibold text-primary-600 hover:underline">+ ďalšie menu</button>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">Uložiť týždeň</x-filament::button>
            <x-filament::button color="gray" type="button" wire:click="discard" wire:confirm="Zahodiť neuložené zmeny?">Zahodiť zmeny</x-filament::button>
        </div>
    </form>

    @if ($impact)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                <h2 class="mb-2 text-lg font-bold text-warning-600">Zmena jedál, ktoré už majú objednávky</h2>
                <p class="mb-4 text-sm">Týchto zamestnancov sa zmena týka. Ich objednávky zostanú platné (pri odstránenom jedle ich kuchyňa stále uvidí). Odporúčame ich informovať.</p>
                @foreach ($impact as $what => $names)
                    <div class="mb-3">
                        <p class="text-sm font-semibold">{{ $what }} – {{ count($names) }} {{ count($names) === 1 ? 'objednávka' : 'objednávok' }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ implode(', ', $names) }}</p>
                    </div>
                @endforeach
                <div class="mt-4 flex gap-3">
                    <x-filament::button color="warning" wire:click="save(true)">Áno, uložiť zmeny</x-filament::button>
                    <x-filament::button color="gray" wire:click="cancelSave">Späť</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
