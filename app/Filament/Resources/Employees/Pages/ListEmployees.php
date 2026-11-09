<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Pages\ImportEmployees;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import z XLSX/CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(ImportEmployees::getUrl()),
            CreateAction::make(),
        ];
    }
}
