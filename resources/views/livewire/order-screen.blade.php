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
        tm: @js((object) $tickets['days']),
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
    <div class="sticky top-[52px] z-20 -mx-3 mb-3 bg-base-200 px-3 pb-2 pt-1 sm:-mx-4 sm:px-4">
        <div class="mb-2 flex items-center justify-between gap-2">
            <button type="button" wire:click="previousWeek" class="btn btn-lg h-12 min-w-12 border-0 bg-base-100 text-lg font-bold shadow-sm" aria-label="Predchádzajúci týždeň">‹</button>
            <div class="text-center">
                <div class="text-base font-semibold">{{ SlovakDate::dm($days->first()) }} – {{ SlovakDate::dm($days->last()) }} {{ $days->last()->format('Y') }}</div>
                @unless (collect($days)->contains(fn ($d) => $d->toDateString() === $today))
                    <button type="button" wire:click="thisWeek" class="text-sm font-semibold text-primary underline">Späť na tento týždeň</button>
                @endunless
            </div>
            <button type="button" wire:click="nextWeek" class="btn btn-lg h-12 min-w-12 border-0 bg-base-100 text-lg font-bold shadow-sm" aria-label="Nasledujúci týždeň">›</button>
        </div>

        <div class="tabs tabs-box grid grid-cols-7 gap-1 bg-transparent p-0 shadow-none lg:hidden" role="tablist">
            @foreach ($days as $day)
                @php $ymd = $day->toDateString(); @endphp
                {{-- full-contrast text: daisyUI dims inactive tabs, too pale for old phones in sunlight --}}
                @php $idle = $ymd === $today ? 'bg-primary-tint text-secondary ring-2 ring-primary' : 'bg-base-100 text-base-content'; @endphp
                <button type="button" role="tab" x-on:click="go('{{ $ymd }}')"
                        :aria-selected="sel === '{{ $ymd }}'"
                        :class="{ 'bg-primary text-primary-content': sel === '{{ $ymd }}', '{{ $idle }}': sel !== '{{ $ymd }}' }"
                        class="tab h-auto flex-col gap-0 rounded-field px-0 py-2 shadow-sm {{ $ymd === $selected ? 'bg-primary text-primary-content' : $idle }}">
                    <span class="text-sm font-semibold">{{ SlovakDate::dayShort($day) }}</span>
                    <span class="text-lg font-bold leading-tight">{{ $day->format('j') }}</span>
                    <span @class(['mt-0.5 h-2 w-2 rounded-full', 'bg-current' => isset($orderDays[$ymd]), 'bg-transparent' => ! isset($orderDays[$ymd])])
                          aria-label="{{ isset($orderDays[$ymd]) ? 'objednané' : '' }}"></span>
                </button>
            @endforeach
        </div>

        {{-- Ticket mode: tickets left in the month of the viewed day (phone) / of the week (desktop) --}}
        @foreach ($tickets['months'] as $m => $chip)
            <div :class="{ 'max-lg:hidden': tm[sel] !== '{{ $m }}' }"
                 @class([
                     'stats mt-2 flex w-full shadow-sm lg:inline-flex lg:w-auto lg:mr-2',
                     'bg-primary text-primary-content' => ! $chip['empty'],
                     'bg-accent text-accent-content' => $chip['empty'],
                     'max-lg:hidden' => ($tickets['days'][$selected] ?? null) !== $m,
                 ])
                 role="status">
                <div class="stat place-items-center px-3 py-2">
                    <div class="stat-value whitespace-normal text-center text-base font-bold leading-snug">{{ $chip['text'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Days: one visible on phones (swipe), the whole week on desktop --}}
    <div class="week-grid grid gap-3" style="--week-rows: {{ $weekRows }}" x-on:touchstart.passive="ts($event)" x-on:touchend.passive="te($event)">
        @foreach ($days as $day)
            @php $ymd = $day->toDateString(); @endphp
            <section class="week-day" :class="{ 'hidden': sel !== '{{ $ymd }}' }" @class(['hidden' => $ymd !== $selected]) aria-label="{{ SlovakDate::long($day) }}">
                <h2 @class(['mb-2 text-lg font-bold lg:mb-0 lg:self-end lg:text-base', 'text-secondary' => $ymd === $today])>
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
                        <div class="week-slot card block bg-base-100 p-3 shadow-sm lg:p-2" style="--slot-rows: {{ $slotRows[$mealSlot->id]['rows'] }}">
                            <div class="mb-2 flex items-baseline justify-between gap-2 lg:mb-0 lg:flex-col lg:items-start lg:gap-1">
                                <h3 class="text-xl font-bold lg:text-base">{{ $mealSlot->name }}</h3>
                                <span @class([
                                    'badge h-auto whitespace-nowrap rounded-full border-0 px-2.5 py-0.5 text-sm font-semibold lg:text-xs',
                                    'bg-success-soft text-success' => $open,
                                    'bg-neutral-soft text-neutral' => ! $open,
                                ])>{{ OrderScreen::statusLabel($a, $day) }}</span>
                            </div>

                            @if ($menu?->soup_name && $items->isNotEmpty())
                                <p class="mb-2 rounded-field bg-accent-soft px-3 py-2 text-base lg:mb-0 lg:text-sm" title="Polievka sa podáva ku každému obedu, neobjednáva sa">
                                    <span class="font-semibold">Polievka:</span> {{ $menu->soup_name }}
                                    @if ($menu->soup_allergens)<span class="text-sm text-neutral"> · alergény {{ $menu->soup_allergens }}</span>@endif
                                </p>
                            @elseif ($slotRows[$mealSlot->id]['soup'])
                                <div class="hidden lg:block" aria-hidden="true"></div>{{-- keeps the soup row aligned --}}
                            @endif
                            @if ($items->isEmpty())
                                <p class="py-2 text-base text-neutral lg:text-sm">Menu zatiaľ nie je zverejnené.</p>
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
                                                        'border-primary bg-primary-soft' => $mine->isNotEmpty(),
                                                        'border-base-300 bg-base-100 active:bg-base-200' => $mine->isEmpty() && $open,
                                                        'border-base-300 bg-base-200 text-neutral' => $mine->isEmpty() && ! $open,
                                                    ])
                                                    aria-pressed="{{ $mine->isNotEmpty() ? 'true' : 'false' }}">
                                                <span @class([
                                                    'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-lg font-bold lg:h-6 lg:w-6 lg:text-sm',
                                                    'border-primary bg-primary text-primary-content' => $mine->isNotEmpty(),
                                                    'border-base-300 text-neutral' => $mine->isEmpty(),
                                                ])>
                                                    @if ($mine->isNotEmpty()) ✓ @else {{ $item->number }} @endif
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-sm font-semibold uppercase tracking-wide text-neutral lg:text-xs">{{ $item->label() }}</span>
                                                    <span class="block text-lg font-semibold leading-snug lg:text-sm">{{ $item->name }}</span>
                                                    @if ($item->allergens)
                                                        <span class="block text-sm text-neutral lg:text-xs" title="{{ collect(Allergens::parse($item->allergens))->map(fn ($n) => $n.' '.Allergens::NAMES[$n])->join(', ') }}">Alergény: {{ $item->allergens }}</span>
                                                    @endif
                                                    @foreach ($mine as $order)
                                                        <span @class([
                                                            'badge mt-1 h-auto whitespace-nowrap rounded-md border-0 px-2 py-0.5 text-sm font-bold lg:text-xs',
                                                            'badge-primary' => ! $order->priceType->isExtra(),
                                                            'badge-warning' => $order->priceType->isExtra(),
                                                        ])>
                                                            {{ $order->priceType->isExtra() ? 'Dokupované' : 'Objednané' }} · {{ Money::format($order->unit_price_cents) }}
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
    <div wire:loading.delay class="fixed inset-x-0 top-0 z-50 h-1 animate-pulse bg-accent" aria-hidden="true"></div>

    {{-- Feedback --}}
    @if ($flash)
        <div wire:key="flash-{{ md5(json_encode($flash).microtime()) }}"
             x-data="{ show: true }" x-init="setTimeout(() => show = false, {{ $flash['type'] === 'ok' ? 4000 : 8000 }})" x-show="show" x-transition.opacity
             class="toast toast-center toast-bottom z-40 mb-20 w-[calc(100%-1.5rem)] max-w-md p-0 sm:mb-4" role="status" aria-live="polite">
            <div @class([
                'alert flex items-start gap-3 rounded-box border-0 p-4 text-lg font-semibold shadow-lg',
                'alert-success bg-secondary text-secondary-content' => $flash['type'] === 'ok',
                'alert-error' => $flash['type'] === 'error',
            ])>
                <span class="flex-1">{{ $flash['text'] }}</span>
                <button type="button" x-on:click="show = false" class="text-2xl leading-none" aria-label="Zavrieť">×</button>
            </div>
        </div>
    @endif

    {{-- Confirmation dialog: native <dialog> (daisyUI modal), opened by Alpine; Esc = Späť --}}
    @if ($dialog)
        <dialog wire:key="dialog-{{ md5(json_encode($dialog)) }}" class="modal modal-bottom sm:modal-middle" aria-labelledby="dialog-title"
                x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeDialog()">
            <div class="modal-box w-full max-w-md rounded-box p-5 max-sm:mb-3 max-sm:w-[calc(100%-1.5rem)] max-sm:justify-self-center">
                <h2 id="dialog-title" class="mb-2 text-2xl font-bold">{{ $dialog['title'] }}</h2>
                <p class="mb-5 text-lg">{{ $dialog['text'] }}</p>

                <div class="space-y-3">
                    @php $big = 'btn btn-lg btn-block h-auto min-h-14 py-3 text-lg font-bold leading-snug'; @endphp
                    @if ($dialog['type'] === 'cancel')
                        @if ($dialog['extra_label'])
                            <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="{{ $big }} btn-accent justify-start text-left">{{ $dialog['extra_label'] }}</button>
                        @endif
                        <button type="button" wire:click="confirmCancel" wire:loading.attr="disabled" class="{{ $big }} btn-error justify-start text-left">{{ $dialog['cancel_label'] }}</button>
                    @elseif ($dialog['type'] === 'change')
                        <button type="button" wire:click="confirmSwitch" wire:loading.attr="disabled" class="{{ $big }} btn-primary justify-start text-left">{{ $dialog['switch_label'] }}</button>
                        @if ($dialog['extra_label'])
                            <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="{{ $big }} btn-accent justify-start text-left">{{ $dialog['extra_label'] }}</button>
                        @endif
                    @elseif ($dialog['type'] === 'extra')
                        <button type="button" wire:click="confirmExtra" wire:loading.attr="disabled" class="{{ $big }} btn-accent text-xl">{{ $dialog['extra_label'] }}</button>
                    @endif
                    <button type="button" wire:click="closeDialog" class="btn btn-lg btn-block h-14 border-base-300 bg-base-100 text-xl font-semibold">Späť</button>
                </div>
            </div>
        </dialog>
    @endif
</div>
