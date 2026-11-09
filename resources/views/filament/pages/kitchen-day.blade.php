@php
    use App\Domain\Ordering\SlotState;
    use App\Support\LocalTime;
    use App\Support\SlovakDate;
    $a = $o->availability;
@endphp
<x-filament-panels::page>
    <div @if ($live) wire:poll.60s @endif class="space-y-4">

        {{-- Date + slot --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button color="gray" icon="heroicon-m-chevron-left" wire:click="shiftDay(-1)" aria-label="Predchádzajúci deň" />
            <input type="date" wire:model.live="date" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800">
            <x-filament::button color="gray" icon="heroicon-m-chevron-right" wire:click="shiftDay(1)" aria-label="Nasledujúci deň" />
            @unless ($isToday)
                <x-filament::button color="gray" wire:click="today">Dnes</x-filament::button>
            @endunless
            <div class="ms-2 flex gap-1">
                @foreach ($mealSlots as $s)
                    <x-filament::button :color="$s->code === $o->slot->code ? 'primary' : 'gray'" wire:click="selectSlot('{{ $s->code }}')">{{ $s->name }}</x-filament::button>
                @endforeach
            </div>
        </div>

        {{-- Heading, status, actions --}}
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold">
                        {{ $o->slot->name }} – <span class="capitalize">{{ SlovakDate::day($o->date) }}</span> {{ $o->date->format('j. n. Y') }}
                        @if ($isToday) <span class="text-base font-semibold text-primary-600">(dnes)</span> @endif
                    </h2>
                    @if ($o->soup)
                        <p class="mt-1 text-sm">Polievka: <strong>{{ $o->soup }}</strong></p>
                    @endif
                    <p class="mt-1 text-sm">
                        @if ($o->lock)
                            <x-filament::badge color="danger" class="inline-flex">Uzamknuté</x-filament::badge>
                            {{ $o->lock->lockedBy?->name ?? '—' }}, {{ LocalTime::toLocal($o->lock->locked_at)->format('j. n. H:i') }}
                        @elseif ($a->state === SlotState::Open)
                            <x-filament::badge color="success" class="inline-flex">Objednávky otvorené</x-filament::badge>
                            uzávierka {{ $a->cutoffAt->isSameDay($o->date) ? '' : SlovakDate::dayShort($a->cutoffAt).' '.SlovakDate::dm($a->cutoffAt) }} o {{ $a->cutoffAt->format('H:i') }} – počty sa ešte môžu meniť
                        @elseif ($a->state === SlotState::Closed)
                            <x-filament::badge color="gray" class="inline-flex">Uzavreté</x-filament::badge> {{ $a->closedDay?->note }}
                        @else
                            <x-filament::badge color="gray" class="inline-flex">{{ $a->state === SlotState::PastCutoff ? 'Po uzávierke' : \Illuminate\Support\Str::ucfirst($a->state->label()) }}</x-filament::badge>
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button tag="a" icon="heroicon-m-printer" target="_blank" :href="route('kitchen.print', [...$query, 'tlac' => 1])">Tlačiť</x-filament::button>
                    <x-filament::button tag="a" color="gray" icon="heroicon-m-document" target="_blank" :href="route('kitchen.pdf', $query)">PDF</x-filament::button>
                    <x-filament::button tag="a" color="gray" icon="heroicon-m-table-cells" :href="route('kitchen.xlsx', $query)">Excel</x-filament::button>
                    @if ($canLock)
                        @if ($o->lock)
                            <x-filament::button color="warning" icon="heroicon-m-lock-open" wire:click="unlock" wire:confirm="Odomknúť? Ak ešte nie je po uzávierke, zamestnanci budú môcť znova objednávať a rušiť.">Odomknúť</x-filament::button>
                        @elseif ($a->state !== SlotState::PastCutoff)
                            <x-filament::button color="danger" icon="heroicon-m-lock-closed" wire:click="lock" wire:confirm="Uzamknúť objednávky na {{ $o->slot->accusative() }} {{ $o->date->format('j. n.') }}? Zamestnanci potom nebudú môcť objednávať ani rušiť.">Uzamknúť</x-filament::button>
                        @endif
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- Totals --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
            <div class="rounded-xl bg-primary-600 p-4 text-white shadow-sm">
                <div class="text-sm font-semibold opacity-90">Spolu</div>
                <div class="text-4xl font-bold">{{ $o->total() }}</div>
                @if ($o->extraCount())<div class="text-sm opacity-90">z toho dokupovaná {{ $o->extraCount() }}</div>@endif
            </div>
            @foreach ($o->totals as $t)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm font-semibold text-gray-500">Menu {{ $t['item']->number }}</div>
                    <div class="text-4xl font-bold">{{ $t['total'] }}</div>
                    <div class="truncate text-sm" title="{{ $t['item']->name }}">{{ $t['item']->name }}</div>
                    @if ($t['extra'])<div class="text-xs font-semibold text-warning-600">z toho dokupovaná {{ $t['extra'] }}</div>@endif
                    @unless ($t['item']->is_active)<div class="text-xs text-danger-600">stiahnuté z ponuky</div>@endunless
                </div>
            @endforeach
        </div>

        {{-- List --}}
        <div x-data="{ q: '', norm: (v) => v.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') }">
        <x-filament::section>
            <x-slot name="heading">Zoznam ({{ $o->total() }})</x-slot>
            <x-slot name="afterHeader">
                <input type="search" x-model="q" placeholder="Hľadať meno…" class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800">
            </x-slot>

            @if ($o->total() === 0)
                <p class="py-6 text-center text-gray-500">Žiadne objednávky.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10">
                            <tr><th class="py-2 pe-2">#</th><th class="py-2 pe-4">Priezvisko Meno</th><th class="py-2 pe-4">Os. č.</th><th class="py-2 pe-4">Stredisko</th><th class="py-2 pe-4">Menu</th><th class="py-2">Poznámka</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($o->rows as $i => $r)
                                <tr data-s="{{ mb_strtolower(\Illuminate\Support\Str::ascii($r['name'].' '.$r['osobne_cislo'])) }}" x-show="! q || $el.dataset.s.includes(norm(q))">
                                    <td class="py-1.5 pe-2 text-gray-400">{{ $i + 1 }}</td>
                                    <td class="py-1.5 pe-4 font-semibold">{{ $r['name'] }}</td>
                                    <td class="py-1.5 pe-4 text-gray-500">{{ $r['osobne_cislo'] }}</td>
                                    <td class="py-1.5 pe-4 text-gray-500">{{ $r['department'] }}</td>
                                    <td class="py-1.5 pe-4">
                                        <strong>Menu {{ $r['number'] }}:</strong> {{ $r['dish'] }}
                                        @if ($r['extra']) <x-filament::badge color="warning" class="ms-1 inline-flex">Dokupovaná</x-filament::badge> @endif
                                    </td>
                                    <td class="py-1.5 text-gray-600">{{ $r['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
