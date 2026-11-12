<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered meal.
 *  - Daily mode (pricing_mode = daily): price_type_id and unit_price_cents are a snapshot
 *    taken when the order was placed (only the after-cancel promotion re-types).
 *  - Ticket mode (pricing_mode = tickets): they are the CURRENT state of the month,
 *    rewritten by App\Domain\Tickets\Repricer after every relevant change.
 *
 * Do not create/modify orders directly — use App\Domain\Ordering\OrderService.
 */
class Order extends Model
{
    protected $fillable = [
        'employee_id', 'date', 'meal_slot_id', 'menu_item_id', 'price_type_id',
        'unit_price_cents', 'pricing_mode', 'status', 'cancelled_at', 'cancelled_by_user_id',
        'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => LocalDate::class,
            'status' => OrderStatus::class,
            'pricing_mode' => PricingMode::class,
            'unit_price_cents' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the DB-level "one active donated order per employee/date/slot" guard in sync.
        // Daily mode only: ticket mode allows several employee-price meals in one slot/day.
        static::saving(function (Order $order) {
            $donatedId = PriceType::byCode(PriceType::DONATED)->id;
            $order->donated_guard = ($order->status === OrderStatus::Active
                && (int) $order->price_type_id === $donatedId
                && ($order->pricing_mode ?? PricingMode::Daily) === PricingMode::Daily) ? 1 : null;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === OrderStatus::Active;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', OrderStatus::Active->value);
    }
}
