<?php

namespace App\Domain\Ordering;

use App\Models\ClosedDay;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\SlotLock;
use App\Support\LocalTime;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Decides whether a meal slot on a date is orderable. Evaluated at request time;
 * no cron is needed for automatic cutoffs.
 *
 * Orderable = slot active, day not closed, before the cutoff, no manual lock,
 * within the ordering horizon, and a menu with at least one active item exists.
 */
class AvailabilityService
{
    public function __construct(private Settings $settings) {}

    /**
     * The automatic cutoff instant for a slot on a date, in local time:
     * (date − cutoff_days_before) at cutoff_time. Ordering is allowed strictly before it.
     */
    public function cutoffAt(DateTimeInterface|string $date, MealSlot $slot): CarbonImmutable
    {
        $day = LocalTime::date($date)->subDays($slot->cutoff_days_before);

        return CarbonImmutable::parse($day->format('Y-m-d').' '.$slot->cutoff_time, LocalTime::zone());
    }

    public function for(DateTimeInterface|string $date, MealSlot $slot, ?DateTimeInterface $now = null): SlotAvailability
    {
        $day = LocalTime::date($date);

        return $this->forPeriod($day, $day, collect([$slot]), $now)[$day->format('Y-m-d')][$slot->id];
    }

    /**
     * Batch evaluation for a date range (e.g. a week strip) with 3 queries total.
     *
     * @param  Collection<int, MealSlot>|null  $slots  defaults to all slots
     * @return array<string, array<int, SlotAvailability>> keyed by Y-m-d, then slot id
     */
    public function forPeriod(DateTimeInterface|string $from, DateTimeInterface|string $to, ?Collection $slots = null, ?DateTimeInterface $now = null): array
    {
        $from = LocalTime::date($from);
        $to = LocalTime::date($to);
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $slots ??= MealSlot::query()->ordered()->get();
        $slotIds = $slots->pluck('id');

        $menus = Menu::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('meal_slot_id', $slotIds)
            ->whereHas('items', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'date', 'meal_slot_id'])
            ->keyBy(fn (Menu $m) => $m->date->format('Y-m-d').'|'.$m->meal_slot_id);

        $locks = SlotLock::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('meal_slot_id', $slotIds)
            ->get()
            ->keyBy(fn (SlotLock $l) => $l->date->format('Y-m-d').'|'.$l->meal_slot_id);

        $closed = ClosedDay::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn (ClosedDay $c) => $c->date->format('Y-m-d'));

        $lastOrderable = LocalTime::toLocal($now)->startOfDay()->addDays($this->settings->orderingHorizonDays());

        $result = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $ymd = $day->format('Y-m-d');
            foreach ($slots as $slot) {
                $cutoff = $this->cutoffAt($day, $slot);
                $closedDay = ($closed[$ymd] ?? collect())
                    ->first(fn (ClosedDay $c) => $c->meal_slot_id === null || $c->meal_slot_id === $slot->id);

                $state = match (true) {
                    ! $slot->is_active => SlotState::SlotInactive,
                    $closedDay !== null => SlotState::Closed,
                    $now->greaterThanOrEqualTo($cutoff) => SlotState::PastCutoff,
                    isset($locks["$ymd|{$slot->id}"]) => SlotState::Locked,
                    $day->greaterThan($lastOrderable) => SlotState::BeyondHorizon,
                    ! isset($menus["$ymd|{$slot->id}"]) => SlotState::NoMenu,
                    default => SlotState::Open,
                };

                $result[$ymd][$slot->id] = new SlotAvailability($state, $cutoff, $closedDay);
            }
        }

        return $result;
    }
}
