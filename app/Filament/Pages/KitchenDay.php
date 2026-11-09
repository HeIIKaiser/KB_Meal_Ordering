<?php

namespace App\Filament\Pages;

use App\Domain\Kitchen\DailyOverview;
use App\Domain\Ordering\SlotLockService;
use App\Domain\Ordering\SlotState;
use App\Enums\NavGroup;
use App\Enums\Role;
use App\Models\MealSlot;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Kitchen daily overview: totals per option, alphabetical list, print / PDF /
 * XLSX, and the manual lock/unlock button for this date + slot.
 */
class KitchenDay extends Page
{
    protected string $view = 'filament.pages.kitchen-day';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Denný prehľad';

    protected static ?string $navigationLabel = 'Denný prehľad (kuchyňa)';

    protected static ?string $slug = 'kuchyna';

    #[Url(as: 'datum')]
    public string $date = '';

    #[Url(as: 'jedlo')]
    public string $slot = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Kitchen, Role::Admin, Role::Payroll);
    }

    public static function canLock(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Kitchen, Role::Admin);
    }

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    public function shiftDay(int $days): void
    {
        $this->date = LocalTime::date($this->date)->addDays(max(-1, min(1, $days)))->toDateString();
    }

    public function today(): void
    {
        $this->date = LocalTime::today()->toDateString();
    }

    public function selectSlot(string $code): void
    {
        $this->slot = $code;
        $this->normalize();
    }

    public function lock(SlotLockService $locks): void
    {
        $this->authorizeLock();
        $locks->lock($this->date, $this->mealSlot(), auth()->user());
        Notification::make()->title('Uzamknuté – zamestnanci už nemôžu objednávať ani rušiť.')->success()->send();
    }

    public function unlock(SlotLockService $locks): void
    {
        $this->authorizeLock();
        $locks->unlock($this->date, $this->mealSlot(), auth()->user());
        Notification::make()->title('Odomknuté.')->success()->send();
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getViewData(): array
    {
        $o = DailyOverview::for($this->date, $this->mealSlot());

        return [
            'o' => $o,
            'mealSlots' => MealSlot::query()->active()->ordered()->get(),
            'isToday' => $o->date->isSameDay(LocalTime::today()),
            'live' => $o->date->gte(LocalTime::today()),
            'canLock' => static::canLock() && $o->availability->state !== SlotState::Closed,
            'query' => ['datum' => $o->date->toDateString(), 'jedlo' => $o->slot->code],
        ];
    }

    private function normalize(): void
    {
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && strtotime($this->date)
            ? LocalTime::date($this->date)->toDateString()
            : LocalTime::today()->toDateString();

        $slots = MealSlot::query()->active()->ordered()->pluck('code');
        if (! $slots->contains($this->slot)) {
            $this->slot = (string) $slots->first();
        }
    }

    private function mealSlot(): MealSlot
    {
        return MealSlot::query()->where('code', $this->slot)->firstOrFail();
    }

    private function authorizeLock(): void
    {
        if (! static::canLock()) {
            throw new AuthorizationException;
        }
    }
}
