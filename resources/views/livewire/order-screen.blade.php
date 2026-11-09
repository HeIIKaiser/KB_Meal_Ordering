@php
    use App\Domain\Ordering\SlotState;
    use App\Livewire\OrderScreen;
    use App\Support\Allergens;
    use App\Support\Money;
    use App\Support\SlovakDate;
@endphp

<div>
<div wire:key="week-{{ $week }}"
     x-data="{
        sel: @js($selected),
        days: @js($days->map->toDateString()->values()),
        sx: null, sy: null,
        go(d) { this.sel = d; window.scrollTo({ top: 0 }) },
        step(dir) {
            const i = this.days.indexOf(this.sel) + dir;
            if (i < 0) { $wire.previousWeek() } else if (i > 6) { $wire.nextWeek() } else { this.go(this.days[i]) }
        },
        ts(e) { this.sx = e.changedTouches[0].clientX; this.sy = e.changedTouches[0].clientY },
        te(e) {
            if (this.sx === null || window.innerWidth >= 1024) return;
            const dx = e.changedTouches[0].clientX - this.sx, dy = e.changedTouches[0].clientY - this.sy;
            if (Math.abs(dx) > 70 && Math.abs(dx) > 2 * Math.abs(dy)) this.step(dx < 0 ? 1 : -1);
            this.sx = null;
        },
     }">

    {{-- Week strip --}}
    <div class="sticky top-[52px] z-20 -mx-3 mb-3 bg-stone-100 px-3 pb-2 pt-1 sm:-mx-4 sm:px-4">
        <div class="mb-2 flex items-center justify-between gap-2">
            <button type="button" wire:click="previousWeek" class="rounded-xl bg-white px-4 py-3 text-lg font-bold shadow-sm active:bg-stone-200" aria-label="Predchádzajúci týždeň">‹</button>
            <div class="text-center">
                <div class="text-base font-semibold">{{ SlovakDate::dm($days->first()) }} – {{ SlovakDate::dm($days->last()) }} {{ $days->last()->format('Y') }}</div>
                @unless (collect($days)->contains(fn ($d) => $d->toDateString() === $today))
                    <button type="button" wire:click="thisWeek" class="text-sm font-semibold text-emerald-700 underline">Späť na tento týždeň</button>
                @endunless
            </div>
            <button type="button" wire:click="nextWeek" class="rounded-xl bg-white px-4 py-3 text-lg font-bold shadow-sm active:bg-stone-200" aria-label="Nasledujúci týždeň">›</button>
        </div>

        <div class="grid grid-cols-7 gap-1 lg:hidden" role="tablist">
            @foreach ($days as $day)
                @php $ymd = $day->toDateString(); @endphp
                @php $idle = $ymd === $today ? 'bg-emerald-100 text-emerald-900 ring-2 ring-emerald-600' : 'bg-white'; @endphp
                <button type="button" role="tab" x-on:click="go('{{ $ymd }}')"
                        :aria-selected="sel === '{{ $ymd }}'"
                        :class="{ 'bg-emerald-700 text-white': sel === '{{ $ymd }}', '{{ $idle }}': sel !== '{{ $ymd }}' }"
                        class="flex flex-col items-center rounded-xl py-2 shadow-sm {{ $ymd === $selected ? 'bg-emerald-700 text-white' : $idle }}">
                    <span class="text-sm font-semibold">{{ SlovakDate::dayShort($day) }}</span>
                    <span class="text-lg font-bold leading-tight">{{ $day->format('j') }}</span>
                    <span @class(['mt-0.5 h-2 w-2 rounded-full', 'bg-current' => isset($orderDays[$ymd]), 'bg-transparent' => ! isset($orderDays[$ymd])])
                          aria-label="{{ isset($orderDays[$ymd]) ? 'objednané' : '' }}"></span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Days: one visible on phones (swipe), the whole week on desktop --}}
    <div class="week-grid grid gap-3" style="--week-rows: {{ $weekRows }}" x-on:touchstart.passive="ts($event)" x-on:touchend.passive="te($event)">
        @foreach ($days as $day)
            @php $ymd = $day->toDateString(); @endphp
            <section class="week-day" :class="{ 'hidden': sel !== '{{ $ymd }}' }" @class(['hidden' => $ymd !== $selected]) aria-label="{{ SlovakDate::long($day) }}">
                <h2 @class(['mb-2 text-lg font-bold lg:mb-0 lg:self-end lg:text-base', 'text-emerald-800' => $ymd === $today])>
                    <span class="capitalize">{{ SlovakDate::day($day) }}</span> {{ SlovakDate::dm($day) }}
                    @if ($ymd === $today) <span class="text-sm font-semibold">(dnes)</span> @endif
                </h2>

                <div class="space-y-3 lg:contents">
                    @foreach ($mealSlots as $mealSlot)
                        @php
                            $a = $availability[$ymd][$mealSlot->id];
                            $open = $a->state === SlotState::Open;
                            $menu = $menus[$ymd.'|'.$mealSlot->id] ?? null;
                            $items = $menu ? $menu->items->filter(fn ($i) => $i->is_active || isset($ordersByItem[$i->id])) : collect();
                        @endphp
                        <div class="week-slot rounded-2xl bg-white p-3 shadow-sm lg:p-2" style="--slot-rows: {{ $slotRows[$mealSlot->id]['rows'] }}">
                            <div class="mb-2 flex items-baseline justify-between gap-2 lg:mb-0 lg:flex-col lg:items-start lg:gap-1">
                                <h3 class="text-xl font-bold lg:text-base">{{ $mealSlot->name }}</h3>
                                <span @class([
                                    'whitespace-nowrap rounded-full px-2.5 py-0.5 text-sm font-semibold lg:text-xs',
                                    'bg-emerald-100 text-emerald-900' => $open,
                                    'bg-stone-200 text-stone-700' => ! $open,
                                ])>{{ OrderScreen::statusLabel($a, $day) }}</span>
                            </div>

                            @if ($menu?->soup_name && $items->isNotEmpty())
                                <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-base lg:mb-0 lg:text-sm" title="Polievka sa podáva ku každému obedu, neobjednáva sa">
                                    <span class="font-semibold">Polievka:</span> {{ $menu->soup_name }}
                                    @if ($menu->soup_allergens)<span class="text-sm text-stone-500"> · alergény {{ $menu->soup_allergens }}</span>@endif
                                </p>
                            @elseif ($slotRows[$mealSlot->id]['soup'])
                                <div class="hidden lg:block" aria-hidden="true"></div>{{-- keeps the soup row aligned --}}
                            @endif
                            @if ($items->isEmpty())
                                <p class="py-2 text-base text-stone-500 lg:text-sm">Menu zatiaľ nie je zverejnené.</p>
                            @else
                                <ul class="space-y-2 lg:contents">
                                    @foreach ($items as $item)
                                        @php $mine = $ordersByItem[$item->id] ?? collect(); @endphp
                                        <li>
                                            <button type="button"
                                                    wire:click="choose({{ $item->id }})"
                                                    wire:loading.attr="disabled"
                                                    @disabled(! $open)
                                                    @class([
                                                        'flex w-full items-start gap-3 rounded-xl border-2 p-3 text-left transition lg:h-full lg:gap-2 lg:p-2',
                                                        'border-emerald-700 bg-emerald-50' => $mine->isNotEmpty(),
                                                        'border-stone-200 bg-white active:bg-stone-100' => $mine->isEmpty() && $open,
                                                        'border-stone-200 bg-stone-50 text-stone-500' => $mine->isEmpty() && ! $open,
                                                    ])
                                                    aria-pressed="{{ $mine->isNotEmpty() ? 'true' : 'false' }}">
                                                <span @class([
                                                    'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-lg font-bold lg:h-6 lg:w-6 lg:text-sm',
                                                    'border-emerald-700 bg-emerald-700 text-white' => $mine->isNotEmpty(),
                                                    'border-stone-300 text-stone-500' => $mine->isEmpty(),
                                                ])>
                                                    @if ($mine->isNotEmpty()) ✓ @else {{ $item->number }} @endif
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-sm font-semibold uppercase tracking-wide text-stone-500 lg:text-xs">{{ $item->label() }}</span>
                                                    <span class="block text-lg font-semibold leading-snug lg:text-sm">{{ $item->name }}</span>
                                                    @if ($item->allergens)
                                                        <span class="block text-sm text-stone-500 lg:text-xs" title="{{ collect(Allergens::parse($item->allergens))->map(fn ($n) => $n.' '.Allergens::NAMES[$n])->join(', ') }}">Alergény: {{ $item->allergens }}</span>
                                                    @endif
                                                    @foreach ($mine as $order)
                                                        <span @class([
                                                            'mt-1 inline-block whitespace-nowrap rounded-md px-2 py-0.5 text-sm font-bold lg:text-xs',
                                                            'bg-emerald-700 text-white' => $order->priceType->isDonated(),
                                                            'bg-amber-500 text-black' => ! $order->priceType->isDonated(),
                                                        ])>
                                                            {{ $order->priceType->isDonated() ? 'Objednané' : 'Dokupované' }} · {{ Money::format($order->unit_price_cents) }}
                                                        </span>
                                                    @endforeach
                                                </span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

</div>{{-- /week wrapper --}}

    {{-- Progress indicator --}}
    <div wire:loading.delay class="fixed inset-x-0 top-0 z-50 h-1 animate-pulse bg-amber-400" aria-hidden="true"></div>

    {{-- Feedback --}}
    @if ($flash)
        <div wire:key="flash-{{ md5(json_encode($flash).microtime()) }}"
             x-data="{ show: true }" x-init="setTimeout(() => show = false, {{ $flash['type'] === 'ok' ? 4000 : 8000 }})" x-show="show" x-transition.opacity
             class="fixed inset-x-3 bottom-24 z-40 mx-auto max-w-md sm:bottom-6" role="status" aria-live="polite">
            <div @class([
                'flex items-start gap-3 rounded-2xl p-4 text-lg font-semibold shadow-lg',
                'bg-emerald-800 text-white' => $flash['type'] === 'ok',
                'bg-red-700 text-white' => $flash['type'] === 'error',
            ])>
                <span class="flex-1">{{ $flash['text'] }}</span>
                <button type="button" x-on:click="show = false" class="text-2xl leading-none" aria-label="Zavrieť">×</button>
            </div>
        </div>
    @endif

    {{-- Confirmation dialog --}}
    @if ($dialog)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="dialog-title"
             x-data x-on:keydown.escape.window="$wire.closeDialog()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <h2 id="dialog-title" class="mb-2 text-2xl font-bold">{{ $dialog['title'] }}</h2>
                <p class="mb-5 text-lg">{{ $dialog['text'] }}</p>

                <div class="space-y-3">
                    @if ($dialog['type'] === 'cancel')
                        @if ($dialog['extra_label'])
                            <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="w-full rounded-xl bg-amber-400 px-4 py-4 text-left text-lg font-bold text-black active:bg-amber-500">{{ $dialog['extra_label'] }}</button>
                        @endif
                        <button type="button" wire:click="confirmCancel" wire:loading.attr="disabled" class="w-full rounded-xl bg-red-700 px-4 py-4 text-left text-lg font-bold text-white active:bg-red-900">{{ $dialog['cancel_label'] }}</button>
                    @elseif ($dialog['type'] === 'change')
                        <button type="button" wire:click="confirmSwitch" wire:loading.attr="disabled" class="w-full rounded-xl bg-emerald-700 px-4 py-4 text-left text-lg font-bold text-white active:bg-emerald-900">{{ $dialog['switch_label'] }}</button>
                        @if ($dialog['extra_label'])
                            <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="w-full rounded-xl bg-amber-400 px-4 py-4 text-left text-lg font-bold text-black active:bg-amber-500">{{ $dialog['extra_label'] }}</button>
                        @endif
                    @elseif ($dialog['type'] === 'extra')
                        <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="w-full rounded-xl bg-amber-400 px-4 py-4 text-xl font-bold text-black active:bg-amber-500">{{ $dialog['extra_label'] }}</button>
                    @endif
                    <button type="button" wire:click="closeDialog" class="w-full rounded-xl border-2 border-stone-300 px-4 py-4 text-xl font-semibold active:bg-stone-100">Späť</button>
                </div>
            </div>
        </div>
    @endif
</div>
