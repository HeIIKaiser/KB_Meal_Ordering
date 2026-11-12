<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The employee's group from effective_from on (meal_group_id NULL = no group). Written by MealGroupService only. */
class EmployeeGroupMembership extends Model
{
    protected $fillable = ['employee_id', 'meal_group_id', 'effective_from'];

    protected function casts(): array
    {
        return ['effective_from' => LocalDate::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MealGroup::class, 'meal_group_id');
    }
}
