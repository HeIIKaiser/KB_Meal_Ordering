<?php

namespace App\Filament\Resources\MealSlots\Pages;

use App\Filament\Resources\MealSlots\MealSlotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMealSlots extends ManageRecords
{
    protected static string $resource = MealSlotResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
