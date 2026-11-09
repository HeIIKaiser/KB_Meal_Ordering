<?php

namespace App\Filament\Pages;

use App\Enums\NavGroup;
use App\Enums\Role;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Payroll exports. The forms submit with GET straight to the download routes,
 * so files stream from disk instead of passing through Livewire.
 */
class Exports extends Page
{
    protected string $view = 'filament.pages.exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Exporty pre mzdy';

    protected static ?string $navigationLabel = 'Exporty';

    protected static ?string $slug = 'exporty';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Payroll, Role::Admin);
    }

    protected function getViewData(): array
    {
        $today = LocalTime::today();
        $prev = $today->subMonthNoOverflow();

        return [
            'defaultMonth' => ($today->day <= 10 ? $prev : $today)->format('Y-m'),
            'from' => $prev->startOfMonth()->toDateString(),
            'to' => $prev->endOfMonth()->toDateString(),
        ];
    }
}
