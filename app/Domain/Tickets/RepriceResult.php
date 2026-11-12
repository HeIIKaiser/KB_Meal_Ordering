<?php

namespace App\Domain\Tickets;

/**
 * What a repricing run changed: one entry per changed order. Used for previews
 * ("N employees, M orders, € before → after") and for messages to the employee.
 */
final class RepriceResult
{
    /** @var list<array{order_id: int, employee_id: int, date: string, meal_slot_id: int, old_type: int, new_type: int, old_cents: int, new_cents: int}> */
    public array $changes = [];

    public function add(array $change): void
    {
        $this->changes[] = $change;
    }

    public function merge(self $other): self
    {
        array_push($this->changes, ...$other->changes);

        return $this;
    }

    public function orders(): int
    {
        return count($this->changes);
    }

    public function employees(): int
    {
        return count(array_unique(array_column($this->changes, 'employee_id')));
    }

    public function centsBefore(): int
    {
        return array_sum(array_column($this->changes, 'old_cents'));
    }

    public function centsAfter(): int
    {
        return array_sum(array_column($this->changes, 'new_cents'));
    }

    public function centsDiff(): int
    {
        return $this->centsAfter() - $this->centsBefore();
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        return array_column($this->changes, 'order_id');
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}
