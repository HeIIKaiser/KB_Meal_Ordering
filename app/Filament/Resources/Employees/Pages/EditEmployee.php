<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\MealGroupService;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Support\LocalTime;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    /** Group fields are not columns: show the latest planned group (or today's) and its date. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $calendar = EmployeeCalendar::for($this->record);
        $last = $calendar->memberships()->last();
        $planned = $last && $last->effective_from->isAfter(LocalTime::today());

        return [
            ...$data,
            'meal_group_id' => $planned ? $last->meal_group_id : $calendar->today()?->id,
            'group_from' => $planned ? $last->effective_from->toDateString() : LocalTime::today()->startOfMonth()->addMonthNoOverflow()->toDateString(),
        ];
    }

    protected function afterSave(): void
    {
        if (! array_key_exists('group_from', $this->data) || ! $this->data['group_from']) {
            return;
        }
        try {
            $result = app(MealGroupService::class)->assign($this->record, $this->data['meal_group_id'] ? (int) $this->data['meal_group_id'] : null, $this->data['group_from'], auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Skupina sa nezmenila')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }
        if (! $result->isEmpty()) {
            Notification::make()->title('Skupina zmenená')->body(MealGroupResource::describe($result))->warning()->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            EmployeeResource::printSlipAction()->label('Tlačiť lístok'),
            EmployeeResource::resetPinAction(),
        ];
    }
}
