<?php

namespace App\Filament\Resources\ClosedDays\Pages;

use App\Filament\Resources\ClosedDays\ClosedDayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClosedDays extends ManageRecords
{
    protected static string $resource = ClosedDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'created_by_user_id' => auth()->id()]),
        ];
    }
}
