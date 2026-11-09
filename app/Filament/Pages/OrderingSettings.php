<?php

namespace App\Filament\Pages;

use App\Enums\NavGroup;
use App\Enums\Role;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Global ordering settings (admin). Changes are written to the audit log. */
class OrderingSettings extends Page
{
    protected string $view = 'filament.pages.ordering-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Nastavenia objednávania';

    protected static ?string $navigationLabel = 'Objednávanie';

    protected static ?string $slug = 'settings';

    public int|string $horizon = 14;

    public int|string $maxExtra = 1;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function mount(Settings $settings): void
    {
        $this->horizon = $settings->orderingHorizonDays();
        $this->maxExtra = $settings->maxExtraPerSlot();
    }

    public function save(Settings $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->validate([
            'horizon' => ['required', 'integer', 'min:0', 'max:90'],
            'maxExtra' => ['required', 'integer', 'min:0', 'max:10'],
        ], [
            'horizon.*' => 'Zadajte počet dní 0 – 90.',
            'maxExtra.*' => 'Zadajte číslo 0 – 10.',
        ]);

        $settings->set(Settings::ORDERING_HORIZON_DAYS, (int) $data['horizon']);
        $settings->set(Settings::MAX_EXTRA_PER_SLOT, (int) $data['maxExtra']);

        Notification::make()->title('Nastavenia uložené')->success()->send();
    }
}
