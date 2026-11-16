<?php

namespace App\Filament\Resources\FixedPrices\Pages;

use App\Filament\Resources\FixedPrices\FixedPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFixedPrices extends ManageRecords
{
    protected static string $resource = FixedPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nová pevná cena')
                ->mutateDataUsing(fn (array $data) => [
                    ...$data,
                    // group XOR person
                    'meal_group_id' => empty($data['employee_id']) ? ($data['meal_group_id'] ?? null) : null,
                    'created_by_user_id' => auth()->id(),
                ]),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Pre skupiny v režime „Pevná cena“ (napr. SZČO). Poradie: osobná cena pre jedlo → osobná cena → cena skupiny pre jedlo → cena skupiny; vždy verzia s najneskorším „Platí od“ do dátumu jedla.';
    }
}
