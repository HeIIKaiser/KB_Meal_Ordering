<?php

namespace App\Domain\Attendance;

/** Result of the attendance rule for one employee and month. */
final readonly class MonthEntitlement
{
    /**
     * @param  int|null  $tickets  meals on ticket-mode days (null = no ticket-mode day this month)
     * @param  array<string, int>  $mandatory  date → automatic meals on mandatory-mode days
     * @param  list<string>  $unfilled  planned past days with nothing filled in
     */
    public function __construct(
        public ?int $tickets,
        public array $mandatory,
        public array $unfilled,
        public bool $hasData,
        public ?bool $locked,
    ) {}

    public function mandatoryTotal(): ?int
    {
        return $this->mandatory === [] ? null : array_sum($this->mandatory);
    }

    public function flags(): array
    {
        return array_filter([
            'unfilled' => $this->unfilled ?: null,
            'not_locked' => $this->locked === false ? true : null,
            'no_data' => $this->hasData ? null : true,
        ]);
    }
}
