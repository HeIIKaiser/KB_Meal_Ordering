<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Pages\ImportEmployees;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        $notPrinted = fn () => Employee::query()->active()->whereNull('slip_printed_at');

        return [
            Action::make('printNew')
                ->label(fn () => 'Nevytlačené lístky ('.$notPrinted()->count().')')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('warning')
                ->visible(fn () => $notPrinted()->exists())
                ->url(fn () => EmployeeResource::slipsUrl($notPrinted()->pluck('id')->all()), shouldOpenInNewTab: true),
            Action::make('import')
                ->label('Import z XLSX/CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(ImportEmployees::getUrl()),
            CreateAction::make(),
        ];
    }
}
