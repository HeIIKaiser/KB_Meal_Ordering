<?php

namespace App\Domain\Menus;

use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Support\Allergens;
use App\Support\LocalTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plans and applies menu changes (from the weekly grid or an import).
 *
 * plan() compares the desired options with the database and reports, per option,
 * what would happen and which employees have active orders on an option that is
 * renamed or removed — the UI must show that list before apply().
 *
 * Options are never deleted (orders reference them): a removed option is
 * deactivated; its existing orders stay (the kitchen still sees them).
 */
class MenuWriter
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const REMOVE = 'remove';

    public const UNCHANGED = 'unchanged';

    /**
     * @param  iterable<array{date: string, meal_slot_id: int, number: int, name: ?string, allergens?: ?string, kind?: string}>  $rows
     *                                                                                                                                  name null/'' = remove; kind 'soup' = the menu's soup (number ignored)
     * @return Collection<int, array<string, mixed>> one entry per row with 'kind', 'action', 'item_id', 'affected' (list of "Priezvisko Meno")
     */
    public function plan(iterable $rows): Collection
    {
        [$soups, $items] = collect($rows)->partition(fn ($r) => ($r['kind'] ?? 'item') === 'soup');

        return $this->planItems($items)->concat($this->planSoups($soups))->values();
    }

    /** Soups live on the menu row; nobody orders them, so a change never affects orders. */
    private function planSoups(Collection $soups): Collection
    {
        if ($soups->isEmpty()) {
            return collect();
        }

        $soups = $soups->map(fn ($r) => [
            'kind' => 'soup',
            'date' => LocalTime::date($r['date'])->toDateString(),
            'meal_slot_id' => (int) $r['meal_slot_id'],
            'number' => 0,
            'name' => filled($r['name'] ?? null) ? trim($r['name']) : null,
            'allergens' => Allergens::normalize($r['allergens'] ?? null),
        ]);

        $menus = Menu::query()
            ->whereIn('date', $soups->pluck('date')->unique()->all())
            ->get()
            ->keyBy(fn (Menu $m) => $m->date->toDateString().'|'.$m->meal_slot_id);

        return $soups->map(function (array $row) use ($menus) {
            $menu = $menus[$row['date'].'|'.$row['meal_slot_id']] ?? null;
            $old = $menu?->soup_name;

            $action = match (true) {
                $old === null && $row['name'] === null => self::UNCHANGED,
                $old === null => self::CREATE,
                $row['name'] === null => self::REMOVE,
                $old !== $row['name'] || $menu->soup_allergens !== $row['allergens'] => self::UPDATE,
                default => self::UNCHANGED,
            };

            return [...$row, 'action' => $action, 'item_id' => null, 'old_name' => $old, 'affected' => []];
        })->values();
    }

    private function planItems(Collection $rows): Collection
    {
        $rows = $rows->map(fn ($r) => [
            'kind' => 'item',
            'date' => LocalTime::date($r['date'])->toDateString(),
            'meal_slot_id' => (int) $r['meal_slot_id'],
            'number' => (int) $r['number'],
            'name' => filled($r['name'] ?? null) ? trim($r['name']) : null,
            'allergens' => Allergens::normalize($r['allergens'] ?? null),
        ]);

        if ($rows->isEmpty()) {
            return collect();
        }

        $items = MenuItem::query()
            ->join('menus', 'menus.id', '=', 'menu_items.menu_id')
            ->whereIn('menus.date', $rows->pluck('date')->unique()->all())
            ->select('menu_items.*', 'menus.date as menu_date', 'menus.meal_slot_id as menu_slot_id')
            ->get()
            ->keyBy(fn ($i) => LocalTime::date($i->menu_date)->toDateString().'|'.$i->menu_slot_id.'|'.$i->number);

        $orders = Order::query()->active()
            ->whereIn('menu_item_id', $items->pluck('id'))
            ->with('employee:id,first_name,last_name,osobne_cislo')
            ->get()
            ->groupBy('menu_item_id');

        return $rows->map(function (array $row) use ($items, $orders) {
            $item = $items[$row['date'].'|'.$row['meal_slot_id'].'|'.$row['number']] ?? null;

            $action = match (true) {
                $item === null && $row['name'] === null => self::UNCHANGED,
                $item === null => self::CREATE,
                $row['name'] === null => $item->is_active ? self::REMOVE : self::UNCHANGED,
                $item->name !== $row['name'] || $item->allergens !== $row['allergens'] || ! $item->is_active => self::UPDATE,
                default => self::UNCHANGED,
            };

            // Only a changed dish name or a removal matters to people who ordered it.
            $relevant = $item && ($action === self::REMOVE || ($action === self::UPDATE && $item->name !== $row['name']));
            $affected = $relevant
                ? ($orders[$item->id] ?? collect())->map(fn (Order $o) => $o->employee->fullNameReversed())->sort()->values()->all()
                : [];

            return [...$row, 'action' => $action, 'item_id' => $item?->id, 'old_name' => $item?->name, 'affected' => $affected];
        })->values();
    }

    /** @param Collection<int, array<string, mixed>> $plan from plan() */
    public function apply(Collection $plan): int
    {
        $changed = 0;

        DB::transaction(function () use ($plan, &$changed) {
            foreach ($plan as $row) {
                if ($row['action'] === self::UNCHANGED) {
                    continue;
                }

                if (($row['kind'] ?? 'item') === 'soup') {
                    $menu = Menu::query()->firstOrCreate(['date' => $row['date'], 'meal_slot_id' => $row['meal_slot_id']]);
                    $menu->update(['soup_name' => $row['name'], 'soup_allergens' => $row['name'] === null ? null : $row['allergens']]);
                } elseif ($row['action'] === self::REMOVE) {
                    MenuItem::query()->findOrFail($row['item_id'])->update(['is_active' => false]);
                } else {
                    $menu = Menu::query()->firstOrCreate(['date' => $row['date'], 'meal_slot_id' => $row['meal_slot_id']]);
                    MenuItem::query()->updateOrCreate(
                        ['menu_id' => $menu->id, 'number' => $row['number']],
                        ['name' => $row['name'], 'allergens' => $row['allergens'], 'is_active' => true],
                    );
                }
                $changed++;
            }
        });

        return $changed;
    }

    /** All affected employees of a plan, grouped as "date slot Menu n" => names. */
    public static function impact(Collection $plan): Collection
    {
        $slots = MealSlot::query()->pluck('name', 'id');

        return $plan->filter(fn ($r) => $r['affected'] !== [])
            ->mapWithKeys(fn ($r) => [
                LocalTime::date($r['date'])->format('j. n. Y').' '.$slots[$r['meal_slot_id']].' – Menu '.$r['number'].' ('.$r['old_name'].($r['action'] === self::REMOVE ? ', odstránené' : ' → '.$r['name']).')' => $r['affected'],
            ]);
    }
}
