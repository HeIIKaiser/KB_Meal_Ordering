<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Employees\VemaNumbers;
use App\Filament\Pages\ImportEmployees;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
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
            Action::make('fillVema')
                ->label(fn () => 'Doplniť Vema čísla ('.app(VemaNumbers::class)->missingCount().')')
                ->icon(Heroicon::OutlinedHashtag)
                ->color('gray')
                ->visible(fn () => auth()->user()?->isAdmin() && app(VemaNumbers::class)->missingCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Doplniť Vema čísla z osobného čísla?')
                ->modalDescription('Zamestnancom bez Vema čísla sa doplní osobné číslo bez núl na začiatku (003 → 3), tak ako ho mzdy dnes zapisujú do Vemy. Existujúce Vema čísla sa nemenia.')
                ->modalSubmitActionLabel('Doplniť')
                ->action(function () {
                    $r = app(VemaNumbers::class)->fillFromOsobneCislo();
                    Notification::make()->title('Doplnených Vema čísel: '.$r['filled'])
                        ->body($r['skipped'] ? 'Preskočené (nečíselné alebo už použité): '.implode(', ', $r['skipped']) : null)
                        ->success()->send();
                }),
            Action::make('import')
                ->label('Import z XLSX/CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(ImportEmployees::getUrl()),
            CreateAction::make(),
        ];
    }
}
