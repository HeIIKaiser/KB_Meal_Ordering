<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard (admins): active employees whose "forma stravy" (meal group) is not set up yet. Hidden at 0. */
class EmployeesWithoutGroup extends StatsOverviewWidget
{
    protected static ?int $sort = -4;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin) && static::count() > 0;
    }

    public static function count(): int
    {
        return Employee::query()->active()->withoutGroup()->count();
    }

    public static function listUrl(): string
    {
        return EmployeeResource::getUrl('index', ['filters' => ['is_active' => ['value' => '1'], 'without_group' => ['isActive' => '1']]]);
    }

    protected function getStats(): array
    {
        $n = static::count();

        return [
            Stat::make('Forma stravy', $n.' '.match (true) {
                $n === 1 => 'aktívny zamestnanec bez formy stravy',
                $n >= 2 && $n <= 4 => 'aktívni zamestnanci bez formy stravy',
                default => 'aktívnych zamestnancov bez formy stravy',
            })
                ->description('Kým nedostanú skupinu, sú v dennom režime. Priradiť: filter „Bez skupiny“ → „Priradiť skupinu“.')
                ->color('warning')
                ->url(static::listUrl()),
        ];
    }
}
