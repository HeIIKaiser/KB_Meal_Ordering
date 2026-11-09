<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Zamestnanec vytvorený – PIN bol vygenerovaný')
            ->actions([
                Action::make('print')
                    ->label('Vytlačiť lístok')
                    ->button()
                    ->url(EmployeeResource::slipsUrl([$this->record->id]), shouldOpenInNewTab: true),
            ]);
    }
}
