<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered meal. price_type_id and unit_price_cents are a snapshot taken when
 * the order was placed; repricing never changes them.
 *
 * Do not create/modify orders directly — use App\Domain\Ordering\OrderService.
 */
class Order extends Model
{
    protected $fillable = [
        'employee_id', 'date', 'meal_slot_id', 'menu_item_id', 'price_type_id',
        'unit_price_cents', 'status', 'cancelled_at', 'cancelled_by_user_id',
        'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => LocalDate::class,
            'status' => OrderStatus::class,
            'unit_price_cents' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the DB-level "one active donated order per employee/date/slot" guard in sync.
        static::saving(function (Order $order) {
            $donatedId = PriceType::byCode(PriceType::DONATED)->id;
            $order->donated_guard = ($order->status === OrderStatus::Active && (int) $order->price_type_id === $donatedId) ? 1 : null;
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
