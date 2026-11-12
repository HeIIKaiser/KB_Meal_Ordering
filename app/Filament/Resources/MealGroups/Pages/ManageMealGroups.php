<?php

namespace App\Filament\Resources\MealGroups\Pages;

use App\Domain\Tickets\MealGroupService;
use App\Enums\PricingMode;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Models\MealGroup;
use App\Support\LocalTime;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMealGroups extends ManageRecords
{
    protected static string $resource = MealGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data) {
                $mode = PricingMode::from($data['initial_mode'] ?? PricingMode::Daily->value);
                unset($data['initial_mode']);
                $group = MealGroup::query()->create($data);
                // No members yet, so starting in ticket mode reprices nothing.
                if ($mode === PricingMode::Tickets) {
                    app(MealGroupService::class)->switchMode($group, PricingMode::Tickets, LocalTime::today()->startOfMonth(), auth()->user());
                }

                return $group;
            }),
        ];
    }

    public function getTitle(): string
    {
        return 'Skupiny stravníkov';
    }

    public function getSubheading(): ?string
    {
        return 'Denný režim: prvý obed a prvá večera dňa za zamestnaneckú cenu. Režim lístkov: mesačný počet lístkov za zamestnaneckú cenu, bez denných limitov. Zamestnanec bez skupiny je v dennom režime.';
    }
}
