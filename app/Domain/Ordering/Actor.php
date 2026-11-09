<?php

namespace App\Domain\Ordering;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who performs an ordering action: the employee themself (self-service, bound by
 * the ordering window) or a staff user (may act after lock, with a reason).
 */
final readonly class Actor
{
    private function __construct(
        public ?Employee $employee,
        public ?User $user,
        public ?string $reason,
    ) {}

    public static function employee(Employee $employee): self
    {
        return new self($employee, null, null);
    }

    public static function staff(User $user, ?string $reason = null): self
    {
        $reason = $reason !== null ? trim($reason) : null;

        return new self(null, $user, $reason === '' ? null : $reason);
    }

    public function isStaff(): bool
    {
        return $this->user !== null;
    }

    public function causer(): Model
    {
        return $this->user ?? $this->employee;
    }
}
