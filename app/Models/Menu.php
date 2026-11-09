<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Menu extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['date', 'meal_slot_id', 'soup_name', 'soup_allergens'];

    protected function casts(): array
    {
        return ['date' => LocalDate::class];
    }

    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    /** "Fazuľová polievka (1,9)" or null */
    public function soupLabel(): ?string
    {
        return $this->soup_name ? trim($this->soup_name.($this->soup_allergens ? ' ('.$this->soup_allergens.')' : '')) : null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('number');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('menus');
    }
}
