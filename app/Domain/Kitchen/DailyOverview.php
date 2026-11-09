<?php

namespace App\Domain\Kitchen;

use App\Domain\Ordering\AvailabilityService;
use App\Domain\Ordering\SlotAvailability;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\SlotLock;
use App\Support\LocalTime;
use App\Support\SlovakDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Kitchen daily overview for one date + slot: totals per menu option and the
 * alphabetical list of active orders. Shared by the screen, the print page,
 * the XLSX and the PDF so they always show the same numbers.
 */
final class DailyOverview
{
    /**
     * @param  Collection<int, array{item: MenuItem, total: int, donated: int, extra: int}>  $totals
     * @param  Collection<int, array{name: string, last_name: string, first_name: string, osobne_cislo: string, department: ?string, number: int, dish: string, extra: bool, price_type: string, note: ?string}>  $rows
     */
    private function __construct(
        public readonly CarbonImmutable $date,
        public readonly MealSlot $slot,
        public readonly SlotAvailability $availability,
        public readonly ?SlotLock $lock,
        public readonly Collection $totals,
        public readonly Collection $rows,
        public readonly ?string $soup = null,
    ) {}

    public static function for(DateTimeInterface|string $date, MealSlot $slot): self
    {
        $date = LocalTime::date($date);
        $donatedId = PriceType::byCode(PriceType::DONATED)->id;
        $types = PriceType::query()->pluck('name', 'id');

        $orders = Order::query()
            ->active()
            ->where('orders.date', $date->toDateString())
            ->where('orders.meal_slot_id', $slot->id)
            ->join('employees', 'employees.id', '=', 'orders.employee_id')
            ->join('menu_items', 'menu_items.id', '=', 'orders.menu_item_id')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('employees.osobne_cislo')
            ->orderBy('orders.price_type_id')
            ->orderBy('orders.id')
            ->get([
                'orders.id', 'orders.menu_item_id', 'orders.price_type_id', 'orders.note',
                'employees.last_name', 'employees.first_name', 'employees.osobne_cislo', 'employees.department',
                'menu_items.number', 'menu_items.name as dish',
            ]);

        $menu = Menu::query()->where('date', $date->toDateString())->where('meal_slot_id', $slot->id)->first();
        $items = $menu ? $menu->items()->get() : collect();
        $byItem = $orders->groupBy('menu_item_id');

        $totals = $items
            ->filter(fn (MenuItem $i) => $i->is_active || isset($byItem[$i->id]))
            ->map(fn (MenuItem $i) => [
                'item' => $i,
                'total' => ($byItem[$i->id] ?? collect())->count(),
                'donated' => ($byItem[$i->id] ?? collect())->where('price_type_id', $donatedId)->count(),
                'extra' => ($byItem[$i->id] ?? collect())->where('price_type_id', '!=', $donatedId)->count(),
            ])
            ->values();

        $rows = $orders->map(fn ($o) => [
            'name' => trim($o->last_name.' '.$o->first_name),
            'last_name' => $o->last_name,
            'first_name' => $o->first_name,
            'osobne_cislo' => $o->osobne_cislo,
            'department' => $o->department,
            'number' => (int) $o->number,
            'dish' => $o->dish,
            'extra' => (int) $o->price_type_id !== $donatedId,
            'price_type' => $types[$o->price_type_id] ?? '',
            'note' => $o->note,
        ]);

        return new self(
            $date,
            $slot,
            app(AvailabilityService::class)->for($date, $slot),
            SlotLock::query()->with('lockedBy')->where('date', $date->toDateString())->where('meal_slot_id', $slot->id)->first(),
            $totals,
            $rows,
            $menu?->soupLabel(),
        );
    }

    public function total(): int
    {
        return $this->rows->count();
    }

    public function extraCount(): int
    {
        return $this->rows->where('extra', true)->count();
    }

    /** "utorok 10. 11. 2026 – Obed" */
    public function title(): string
    {
        return SlovakDate::long($this->date).' – '.$this->slot->name;
    }

    /** e.g. kuchyna_2026-11-10_obed */
    public function fileBase(): string
    {
        return 'kuchyna_'.$this->date->toDateString().'_'.$this->slot->code;
    }

    /**
     * Rows split into two columns for compact printing: [[left, right|null], …],
     * reading down the left column first.
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: int, 3: int|null}> left row, right row, their 1-based numbers
     */
    public function twoColumns(): array
    {
        $rows = $this->rows->values();
        $half = (int) ceil($rows->count() / 2);
        $out = [];
        for ($i = 0; $i < $half; $i++) {
            $out[] = [$rows[$i], $rows[$i + $half] ?? null, $i + 1, isset($rows[$i + $half]) ? $i + $half + 1 : null];
        }

        return $out;
    }
}
