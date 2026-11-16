<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\AttendanceCalculator;
use App\Domain\Attendance\AttendanceRoster;
use App\Domain\Attendance\AttendanceSync;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\NavGroup;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Models\AttendanceDay;
use App\Models\AttendanceResult;
use App\Models\AttendanceSync as SyncLog;
use App\Models\ClosedMonth;
use App\Models\Employee;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * „Dochádzka“ (docs/ATTENDANCE.md §6.1): sync state of a month, locked attendance,
 * unfilled planned days, unmatched numbers, late changes after closing, and every
 * ticket/mandatory employee's numbers. Payroll reads; the admin syncs and finalises.
 */
class AttendanceStatus extends Page
{
    protected string $view = 'filament.pages.attendance-status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Dochádzka';

    protected static ?string $navigationLabel = 'Dochádzka';

    protected static ?string $slug = 'dochadzka';

    #[Url(as: 'mesiac')]
    public string $month = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin, Role::Payroll);
    }

    public function mount(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::current();
        }
    }

    public function updatedMonth(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::current();
        }
    }

    public function previousMonth(): void
    {
        $this->month = Month::previous($this->month);
    }

    public function nextMonth(): void
    {
        $this->month = Month::next($this->month);
    }

    public function requestSync(AttendanceSync $sync): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $months = array_values(array_unique([Month::previous(Month::current()), Month::current(), $this->month]));
        $sync->request($months);
        Notification::make()->title('Synchronizácia vyžiadaná')->body('Most na DOCKER01 sa ozve do 5 minút. Stránka sa obnoví sama.')->success()->send();
    }

    protected function getViewData(): array
    {
        $sync = app(AttendanceSync::class);
        $roster = app(AttendanceRoster::class)->employees($this->month);
        $range = [Month::first($this->month)->toDateString(), Month::last($this->month)->toDateString()];
        $days = AttendanceDay::query()->whereBetween('date', $range)->get()->groupBy('osobne_cislo');
        $results = AttendanceResult::query()->where('month', $this->month)->get()->keyBy('employee_id');
        $alloc = app(TicketAllocation::class);
        $closed = ClosedMonth::query()->where('month', $this->month)->first();
        $lastData = $sync->lastSuccess($this->month);

        $calculator = app(AttendanceCalculator::class);
        $rows = $roster->map(function ($r) use ($days, $results, $alloc, $calculator) {
            $e = $r['employee'];
            $mine = ($days[$e->attendanceKey()] ?? collect())->keyBy(fn ($d) => $d->date->toDateString());
            $res = $results[$e->id] ?? null;

            return [
                'employee' => $e,
                'modes' => collect($r['modes'])->map(fn (PricingMode $m) => $m === PricingMode::Tickets ? 'lístky' : 'povinná')->join(' + '),
                'has_data' => $mine->isNotEmpty(),
                'locked' => $mine->isNotEmpty() && $mine->every(fn ($d) => $d->month_locked),
                'result' => $res,
                'allocation' => in_array(PricingMode::Tickets, $r['modes'], true) ? $alloc->allocationWithSource($e, $this->month, $r['calendar']) : null,
                // live: planned past days with nothing filled in (the estimate counts them by plan)
                'unfilled' => $mine->isEmpty() ? [] : $calculator->compute($e, $this->month, false, $r['calendar'], $mine)->unfilled,
            ];
        });

        return [
            'label' => Month::label($this->month),
            'rows' => $rows,
            'lastData' => $lastData,
            'lastAny' => $sync->lastSuccess(),
            'lastStatus' => SyncLog::query()->where('kind', 'status')->latest('received_at')->first(),
            'lastError' => SyncLog::query()->where('ok', false)->latest('received_at')->first(),
            'pending' => $sync->pending(),
            'withData' => $rows->where('has_data', true)->count(),
            'locked' => $rows->where('locked', true)->count(),
            'missing' => $rows->where('has_data', false),
            'unfilled' => $rows->filter(fn ($r) => $r['unfilled']),
            'unmatched' => $lastData?->unmatched ?? [],
            'late' => $closed ? SyncLog::query()->where('kind', 'data')->where('month', $this->month)->whereNotNull('late_changes')
                ->where('received_at', '>=', $closed->closed_at)->latest('received_at')->first() : null,
            'closed' => $closed,
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
            'sources' => TicketAllocation::SOURCES,
        ];
    }
}
