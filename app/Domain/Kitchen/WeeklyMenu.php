<?php

namespace App\Domain\Kitchen;

use App\Models\ClosedDay;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Support\LocalTime;
use App\Support\SlovakDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The week's menu (Monday–Sunday) for the printable A4 board version.
 * Columns adapt to the data: per active slot a soup column (if the slot has soups)
 * and as many option columns as the busiest day of the week needs.
 */
final class WeeklyMenu
{
    /**
     * @param  Collection<int, MealSlot>  $slots
     * @param  list<array{slot: MealSlot, kind: string, number: int|null, label: string}>  $columns
     * @param  list<array{date: CarbonImmutable, closed: ?string, cells: list<array{name: string, allergens: ?string}|null>}>  $days
     */
    private function __construct(
        public readonly CarbonImmutable $monday,
        public readonly array $columns,
        public readonly array $days,
    ) {}

    public static function for(DateTimeInterface|string $anyDay): self
    {
        $monday = LocalTime::date($anyDay)->startOfWeek();
        $sunday = $monday->addDays(6);
        $slots = MealSlot::query()->active()->ordered()->get();

        $menus = Menu::query()
            ->with(['items' => fn ($q) => $q->where('is_active', true)])
            ->whereBetween('date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereIn('meal_slot_id', $slots->pluck('id'))
            ->get()
            ->keyBy(fn (Menu $m) => $m->date->toDateString().'|'.$m->meal_slot_id);

        $closed = ClosedDay::query()
            ->whereBetween('date', [$monday->toDateString(), $sunday->toDateString()])
            ->get()
            ->groupBy(fn (ClosedDay $c) => $c->date->toDateString());

        // columns: soup (if any that week) + Menu 1..n per slot
        $columns = [];
        foreach ($slots as $slot) {
            $slotMenus = $menus->filter(fn (Menu $m) => $m->meal_slot_id === $slot->id);
            if ($slot->has_soup && $slotMenus->contains(fn (Menu $m) => filled($m->soup_name))) {
                $columns[] = ['slot' => $slot, 'kind' => 'soup', 'number' => null, 'label' => 'Polievka'];
            }
            $numbers = $slotMenus->flatMap(fn (Menu $m) => $m->items->pluck('number'))->unique()->sort()->values();
            if ($numbers->isEmpty()) {
                $numbers = collect([1]);
            }
            foreach ($numbers as $n) {
                $columns[] = [
                    'slot' => $slot,
                    'kind' => 'item',
                    'number' => $n,
                    'label' => $slot->name.($numbers->count() > 1 ? ' – Menu '.$n : ''),
                ];
            }
        }

        $days = [];
        for ($d = $monday; $d->lte($sunday); $d = $d->addDay()) {
            $ymd = $d->toDateString();
            $closedAll = ($closed[$ymd] ?? collect())->first(fn (ClosedDay $c) => $c->meal_slot_id === null);

            $cells = [];
            foreach ($columns as $col) {
                $closedSlot = ($closed[$ymd] ?? collect())->first(fn (ClosedDay $c) => $c->meal_slot_id === $col['slot']->id);
                $menu = $menus[$ymd.'|'.$col['slot']->id] ?? null;

                $cells[] = match (true) {
                    $closedAll !== null => null,
                    $closedSlot !== null => ['name' => 'Uzavreté'.($closedSlot->note ? ' – '.$closedSlot->note : ''), 'allergens' => null, 'closed' => true],
                    $menu === null => null,
                    $col['kind'] === 'soup' => $menu->soup_name ? ['name' => $menu->soup_name, 'allergens' => $menu->soup_allergens, 'closed' => false] : null,
                    default => ($item = $menu->items->firstWhere('number', $col['number']))
                        ? ['name' => $item->name, 'allergens' => $item->allergens, 'closed' => false]
                        : null,
                };
            }

            $days[] = [
                'date' => $d,
                'closed' => $closedAll ? trim('Uzavreté '.($closedAll->note ? '– '.$closedAll->note : '')) : null,
                'cells' => $cells,
            ];
        }

        return new self($monday, $columns, $days);
    }

    public function isEmpty(): bool
    {
        return collect($this->days)->every(fn ($d) => $d['closed'] === null && collect($d['cells'])->filter()->isEmpty());
    }

    /** "16. – 22. 11. 2026" / "28. 12. 2026 – 3. 1. 2027" */
    public function period(): string
    {
        $sunday = $this->monday->addDays(6);

        return $this->monday->year === $sunday->year
            ? SlovakDate::dm($this->monday).' – '.SlovakDate::dm($sunday).' '.$sunday->format('Y')
            : SlovakDate::dm($this->monday).' '.$this->monday->format('Y').' – '.SlovakDate::dm($sunday).' '.$sunday->format('Y');
    }

    public function fileBase(): string
    {
        return 'jedalny_listok_'.$this->monday->toDateString();
    }
}
