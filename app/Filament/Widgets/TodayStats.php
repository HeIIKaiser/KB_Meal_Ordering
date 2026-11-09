<?php

namespace App\Filament\Widgets;

use App\Domain\Ordering\AvailabilityService;
use App\Domain\Ordering\SlotState;
use App\Filament\Pages\KitchenDay;
use App\Models\MealSlot;
use App\Models\Order;
use App\Support\LocalTime;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: today's and tomorrow's order counts per slot, linking to the kitchen overview. */
class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return KitchenDay::canAccess();
    }

    protected function getStats(): array
    {
        $today = LocalTime::today();
        $tomorrow = $today->addDay();
        $slots = MealSlot::query()->active()->ordered()->get();
        $availability = app(AvailabilityService::class)->forPeriod($today, $tomorrow, $slots);

        $counts = Order::query()->active()
            ->whereIn('date', [$today->toDateString(), $tomorrow->toDateString()])
            ->selectRaw('date, meal_slot_id, COUNT(*) as n')
            ->groupBy('date', 'meal_slot_id')
            ->get()
            ->mapWithKeys(fn ($r) => [LocalTime::date($r->date)->toDateString().'|'.$r->meal_slot_id => (int) $r->n]);

        $stats = [];
        foreach ([$today, $tomorrow] as $day) {
            foreach ($slots as $slot) {
                $a = $availability[$day->toDateString()][$slot->id];
                $stats[] = Stat::make(($day->isSameDay($today) ? 'Dnes' : 'Zajtra').' – '.$slot->name, $counts[$day->toDateString().'|'.$slot->id] ?? 0)
                    ->description(match ($a->state) {
                        SlotState::Open => 'otvorené do '.($a->cutoffAt->isSameDay($day) ? '' : 'dňa '.$a->cutoffAt->format('j. n.').' ').$a->cutoffAt->format('H:i'),
                        SlotState::Locked => 'uzamknuté',
                        SlotState::PastCutoff => 'po uzávierke',
                        default => $a->state->label(),
                    })
                    ->color($a->state === SlotState::Open ? 'success' : 'gray')
                    ->url(KitchenDay::getUrl(['datum' => $day->toDateString(), 'jedlo' => $slot->code]));
            }
        }

        return $stats;
    }
}
