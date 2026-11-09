<?php

namespace App\Filament\Pages;

use App\Domain\Menus\MenuWriter;
use App\Enums\NavGroup;
use App\Enums\Role;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Support\Allergens;
use App\Support\LocalTime;
use App\Support\SlovakDate;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Weekly menu grid: days × slots × options, for quick manual entry.
 * Renaming/removing an option that already has orders shows the affected
 * employees and needs a second confirmation.
 */
class MenuWeek extends Page
{
    protected string $view = 'filament.pages.menu-week';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Menus;

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Jedálny lístok – týždeň';

    protected static ?string $navigationLabel = 'Jedálny lístok';

    protected static ?string $slug = 'menu';

    public const MIN_ROWS = 3;

    #[Url(as: 'tyzden')]
    public string $week = '';

    /** @var array<string, list<array{number: int, name: string, allergens: string}>> keyed "Y-m-d|slotId" */
    public array $grid = [];

    /** @var array<string, list<string>>|null affected employees awaiting confirmation */
    public ?array $impact = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin, Role::Kitchen);
    }

    public function mount(): void
    {
        $monday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) ? LocalTime::date($this->week)->startOfWeek() : LocalTime::today()->startOfWeek()->addWeek();
        $this->week = $monday->toDateString();
        $this->load();
    }

    public function previousWeek(): void
    {
        $this->week = $this->monday()->subWeek()->toDateString();
        $this->load();
    }

    public function nextWeek(): void
    {
        $this->week = $this->monday()->addWeek()->toDateString();
        $this->load();
    }

    public function addRow(string $key): void
    {
        abort_unless(isset($this->grid[$key]), 404);
        $next = (collect($this->grid[$key])->max('number') ?? 0) + 1;
        $this->grid[$key][] = ['number' => $next, 'name' => '', 'allergens' => ''];
    }

    /** Fill EMPTY cells from the previous week (doesn't overwrite anything). */
    public function copyPreviousWeek(): void
    {
        $prev = $this->loadGrid($this->monday()->subWeek());
        $copied = 0;

        foreach ($this->grid as $key => $rows) {
            if (collect($rows)->contains(fn ($r) => trim($r['name']) !== '')) {
                continue;
            }
            [$date, $slotId] = explode('|', $key);
            $prevKey = LocalTime::date($date)->subWeek()->toDateString().'|'.$slotId;
            if (collect($prev[$prevKey] ?? [])->contains(fn ($r) => $r['name'] !== '')) {
                $this->grid[$key] = $prev[$prevKey];
                $copied++;
            }
        }

        Notification::make()->title($copied ? "Skopírované dni/jedlá: $copied. Skontrolujte a uložte." : 'Nie je čo kopírovať (prázdne bunky v minulom týždni).')->info()->send();
    }

    public function save(bool $confirmed = false): void
    {
        abort_unless(static::canAccess(), 403);

        $errors = [];
        $rows = [];
        foreach ($this->grid as $key => $options) {
            [$date, $slotId] = explode('|', $key);
            foreach ($options as $i => $o) {
                $name = trim((string) $o['name']);
                if (mb_strlen($name) > 255) {
                    $errors["grid.$key.$i.name"] = 'Názov je príliš dlhý.';
                }
                if (! Allergens::isValid($o['allergens'] ?? '')) {
                    $errors["grid.$key.$i.allergens"] = 'Alergény: čísla 1 – 14.';
                }
                $rows[] = ['date' => $date, 'meal_slot_id' => (int) $slotId, 'number' => (int) $o['number'], 'name' => $name, 'allergens' => $o['allergens'] ?? null];
            }
        }

        if ($errors) {
            $this->resetErrorBag();
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }
            Notification::make()->title('Opravte označené polia.')->danger()->send();

            return;
        }

        $writer = app(MenuWriter::class);
        $plan = $writer->plan($rows);
        $impact = MenuWriter::impact($plan);

        if ($impact->isNotEmpty() && ! $confirmed) {
            $this->impact = $impact->all();

            return;
        }

        $changed = $writer->apply($plan);
        $this->impact = null;
        $this->load();

        Notification::make()->title($changed ? "Uložené ($changed zmien)." : 'Žiadne zmeny.')->success()->send();
    }

    public function discard(): void
    {
        $this->load();
    }

    public function cancelSave(): void
    {
        $this->impact = null;
    }

    public function getSubheading(): ?string
    {
        $m = $this->monday();

        return SlovakDate::dm($m).' – '.SlovakDate::dm($m->addDays(6)).' '.$m->addDays(6)->format('Y');
    }

    protected function getViewData(): array
    {
        return [
            'days' => collect(range(0, 6))->map(fn ($i) => $this->monday()->addDays($i)),
            'mealSlots' => MealSlot::query()->active()->ordered()->get(),
        ];
    }

    private function load(): void
    {
        $this->grid = $this->loadGrid($this->monday());
        $this->impact = null;
        $this->resetErrorBag();
    }

    /** @return array<string, list<array{number: int, name: string, allergens: string}>> */
    private function loadGrid(CarbonImmutable $monday): array
    {
        $slots = MealSlot::query()->active()->ordered()->get();
        $menus = Menu::query()
            ->with(['items' => fn ($q) => $q->where('is_active', true)])
            ->whereBetween('date', [$monday->toDateString(), $monday->addDays(6)->toDateString()])
            ->get()
            ->keyBy(fn (Menu $m) => $m->date->toDateString().'|'.$m->meal_slot_id);

        $grid = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i)->toDateString();
            foreach ($slots as $slot) {
                $key = "$date|{$slot->id}";
                $items = ($menus[$key] ?? null)?->items->keyBy('number') ?? collect();
                $max = max(self::MIN_ROWS, (int) $items->keys()->max());
                $grid[$key] = collect(range(1, $max))->map(fn ($n) => [
                    'number' => $n,
                    'name' => $items[$n]->name ?? '',
                    'allergens' => $items[$n]->allergens ?? '',
                ])->all();
            }
        }

        return $grid;
    }

    private function monday(): CarbonImmutable
    {
        return LocalTime::date($this->week);
    }
}
