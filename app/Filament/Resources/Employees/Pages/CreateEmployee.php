<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationMessage(): ?string
    {
        return 'Zamestnanec vytvorený. Vygenerujte mu PIN tlačidlom „Reset PIN“.';
    }
}
