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
                // No members yet, so starting in another mode reprices nothing.
                if ($mode !== PricingMode::Daily) {
                    app(MealGroupService::class)->switchMode($group, $mode, LocalTime::today()->startOfMonth(), auth()->user());
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
        return 'Denný režim: prvý obed a prvá večera dňa za zamestnaneckú cenu. Režim lístkov: mesačný počet lístkov za zamestnaneckú cenu (podľa dochádzky), bez denných limitov. Povinná strava: jedlá za každý odpracovaný deň sa účtujú automaticky, jedlá z aplikácie sú dokupované. Zamestnanec bez skupiny je v dennom režime.';
    }
}
