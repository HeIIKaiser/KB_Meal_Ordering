<?php

namespace App\Domain\Tickets;

use App\Domain\Ordering\OrderingException;
use App\Models\ClosedMonth;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Closed months: after the month-end import the admin closes a month; no order,
 * allocation, override or price-type change with a meal date in it is possible
 * any more (any mode), until an admin reopens it with a reason.
 */
class ClosedMonths
{
    public function isClosed(string $month): bool
    {
        return ClosedMonth::query()->where('month', $month)->exists();
    }

    public function assertOpen(DateTimeInterface|string $dateOrMonth): void
    {
        $month = is_string($dateOrMonth) && Month::valid($dateOrMonth) ? $dateOrMonth : Month::of($dateOrMonth);

        if ($this->isClosed($month)) {
            throw new OrderingException('Mesiac '.Month::label($month).' je uzavretý – zmeny už nie sú možné. Administrátor ho môže znova otvoriť.', 'month_closed');
        }
    }

    public function close(string $month, User $by): void
    {
        $this->assertAdmin($by);
        if ($this->isClosed($month)) {
            return;
        }

        ClosedMonth::query()->create(['month' => $month, 'closed_at' => now(), 'closed_by_user_id' => $by->id]);
        activity('tickets')->causedBy($by)->event('month_closed')->withProperties(['month' => $month])->log('Mesiac uzavretý: '.Month::label($month));
    }

    public function reopen(string $month, User $by, ?string $reason): void
    {
        $this->assertAdmin($by);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new OrderingException('Pri otvorení mesiaca je povinný dôvod.', 'reason_required');
        }

        ClosedMonth::query()->where('month', $month)->delete();
        activity('tickets')->causedBy($by)->event('month_reopened')->withProperties(['month' => $month, 'reason' => $reason])->log('Mesiac znova otvorený: '.Month::label($month));
    }

    /** @return list<string> closed months among the given ones */
    public function closedAmong(array $months): array
    {
        return ClosedMonth::query()->whereIn('month', $months)->pluck('month')->all();
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->is_active || ! $user->isAdmin()) {
            throw new AuthorizationException;
        }
    }
}
