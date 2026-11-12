<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Tickets\MealGroupService;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function afterFill(): void
    {
        $this->data['group_from'] = LocalTime::today()->startOfMonth()->toDateString();
    }

    protected function afterCreate(): void
    {
        if (! empty($this->data['meal_group_id']) && ! empty($this->data['group_from'])) {
            app(MealGroupService::class)->assign($this->record, (int) $this->data['meal_group_id'], $this->data['group_from'], auth()->user());
        }
    }

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
