<?php

namespace App\Domain\Tickets;

use App\Domain\Ordering\OrderingException;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Models\Order;
use App\Models\User;
use App\Support\LocalTime;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Group mode switches and group membership changes — both dated, both reprice the
 * already ordered meals they affect (and only those), audited.
 */
class MealGroupService
{
    public function __construct(
        private Repricer $repricer,
        private ClosedMonths $closedMonths,
    ) {}

    /** What switchMode() would change (nothing is saved). */
    public function previewSwitch(MealGroup $group, PricingMode $mode, DateTimeInterface|string $from, User $by): RepriceResult
    {
        return Simulation::run(fn () => $this->switchMode($group, $mode, $from, $by));
    }

    /** The group uses $mode for meals dated on/after $from. Earlier meals keep their pricing. */
    public function switchMode(MealGroup $group, PricingMode $mode, DateTimeInterface|string $from, User $by): RepriceResult
    {
        $this->assertAdmin($by);
        $from = LocalTime::date($from);

        return DB::transaction(function () use ($group, $mode, $from, $by) {
            $this->closedMonths->assertOpen($from);
            $employees = $this->lock(EmployeeGroupMembership::query()->where('meal_group_id', $group->id)->distinct()->pluck('employee_id'));

            MealGroupMode::query()->updateOrCreate(
                ['meal_group_id' => $group->id, 'effective_from' => $from->toDateString()],
                ['mode' => $mode, 'created_by_user_id' => $by->id],
            );
            activity('tickets')->performedOn($group)->causedBy($by)->event('mode_switched')
                ->withProperties(['mode' => $mode->value, 'effective_from' => $from->toDateString()])
                ->log('Režim skupiny '.$group->name.': '.$mode->label().' od '.$from->format('j. n. Y'));

            $result = new RepriceResult;
            foreach ($employees as $employee) {
                $result->merge($this->repriceFrom($employee, $from, 'mode_switch', $by));
            }

            return $result;
        });
    }

    /** What assign() would change (nothing is saved). */
    public function previewAssign(Employee $employee, ?int $groupId, DateTimeInterface|string $from, User $by): RepriceResult
    {
        return Simulation::run(fn () => $this->assign($employee, $groupId, $from, $by));
    }

    /**
     * The employee belongs to $groupId (null = no group) from $from on. Later planned
     * changes are replaced. Default date in the UI is the 1st of next month, so meals
     * already ordered this month are never repriced silently.
     */
    public function assign(Employee $employee, ?int $groupId, DateTimeInterface|string $from, ?User $by): RepriceResult
    {
        if ($by) {
            $this->assertAdmin($by);
        }
        $from = LocalTime::date($from);

        return DB::transaction(function () use ($employee, $groupId, $from, $by) {
            $this->lock(collect([$employee->id]));
            $calendar = EmployeeCalendar::for($employee);
            $current = $calendar->membershipOn($from);
            $later = $calendar->memberships()->filter(fn ($m) => $m->effective_from > $from);

            if (($current?->meal_group_id) === $groupId && $later->isEmpty()) {
                return new RepriceResult; // no change
            }
            $this->closedMonths->assertOpen($from);

            EmployeeGroupMembership::query()->where('employee_id', $employee->id)->where('effective_from', '>', $from->toDateString())->delete();
            EmployeeGroupMembership::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'effective_from' => $from->toDateString()],
                ['meal_group_id' => $groupId],
            );

            $name = $groupId ? MealGroup::query()->whereKey($groupId)->value('name') : 'bez skupiny';
            activity('employees')->performedOn($employee)->causedBy($by)->event('group_changed')
                ->withProperties(['old' => ['group' => $current?->group?->name], 'attributes' => ['group' => $name], 'effective_from' => $from->toDateString()])
                ->log('Skupina stravníkov: '.$name.' od '.$from->format('j. n. Y'));

            return $this->repriceFrom($employee, $from, 'group_change', $by);
        });
    }

    /** Reprice every month that has active orders on/after $from (month must be open). */
    private function repriceFrom(Employee $employee, DateTimeInterface $from, string $cause, ?User $by): RepriceResult
    {
        $months = Order::query()
            ->where('employee_id', $employee->id)
            ->where('status', OrderStatus::Active->value)
            ->where('date', '>=', LocalTime::date($from)->toDateString())
            ->pluck('date')
            ->map(fn ($d) => Month::of($d))
            ->unique()->sort()->values();

        $result = new RepriceResult;
        $calendar = EmployeeCalendar::for($employee);
        foreach ($months as $month) {
            $this->closedMonths->assertOpen($month);
            $result->merge($this->repricer->reprice($employee, $month, $cause, $by, $calendar));
        }

        return $result;
    }

    /** Lock employee rows in id order (no deadlocks between two admin actions). @return Collection<int, Employee> */
    private function lock(Collection $ids): Collection
    {
        return Employee::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->is_active || ! $user->isAdmin()) {
            throw new AuthorizationException;
        }
    }

    /** Validation helper for forms: the date must not be in a closed month. */
    public function assertDateOpen(DateTimeInterface|string $date): void
    {
        try {
            $this->closedMonths->assertOpen($date);
        } catch (OrderingException $e) {
            throw $e;
        }
    }
}
