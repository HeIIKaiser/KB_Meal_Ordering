<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\AttendanceSync;
use App\Domain\Attendance\Finalizer;
use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\Month;
use App\Enums\NavGroup;
use App\Enums\Role;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * „Finalizovať mesiac“ (docs/ATTENDANCE.md §6.2): fresh sync → preview (only changed
 * employees, toggle for all) → save → close the month.
 */
class FinalizeMonth extends Page
{
    protected string $view = 'filament.pages.finalize-month';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?string $title = 'Finalizovať mesiac';

    protected static ?string $slug = 'dochadzka/finalizovat';

    #[Url(as: 'mesiac')]
    public string $month = '';

    public bool $showAll = false;

    /** @var list<array<string, mixed>>|null */
    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public bool $saved = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function mount(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::previous(Month::current());
        }
    }

    public function updatedMonth(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::previous(Month::current());
        }
        $this->reset(['preview', 'saved']);
    }

    public function requestSync(AttendanceSync $sync): void
    {
        $sync->request([$this->month]);
        $this->reset(['preview', 'saved']);
    }

    public function makePreview(Finalizer $finalizer): void
    {
        try {
            $this->preview = $finalizer->preview($this->month, auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();
        }
    }

    public function save(Finalizer $finalizer): void
    {
        abort_unless($this->preview !== null, 403);
        try {
            $rows = $finalizer->finalize($this->month, auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->preview = $rows;
        $this->saved = true;
        Notification::make()->title('Mesiac finalizovaný')->body('Konečné lístky a povinná strava sú uložené, ceny prepočítané.')->success()->send();
    }

    public function closeMonth(ClosedMonths $closed): void
    {
        abort_unless($this->saved, 403);
        $closed->close($this->month, auth()->user());
        Notification::make()->title('Mesiac '.Month::label($this->month).' uzavretý')->success()->send();
        $this->redirect(Exports::getUrl());
    }

    protected function getViewData(): array
    {
        $sync = app(AttendanceSync::class);
        $pending = $sync->pending();
        $last = $sync->lastSuccess($this->month);
        $requestedAt = $pending['requested_at'] ? CarbonImmutable::parse($pending['requested_at']) : null;
        $fresh = $last && $requestedAt && $last->received_at->gte($requestedAt) && in_array($this->month, $pending['months'] ?: [$this->month], true);

        return [
            'label' => Month::label($this->month),
            'closed' => app(ClosedMonths::class)->isClosed($this->month),
            'pending' => $pending['pending'] && in_array($this->month, $pending['months'], true),
            'last' => $last,
            'fresh' => $fresh,
            'early' => LocalTime::today()->lt(Month::first(Month::next($this->month))->addDays(10)),
            'rows' => collect($this->preview ?? [])->filter(fn ($r) => $this->showAll || $r['changed'])->values(),
            'totals' => [
                'employees' => count($this->preview ?? []),
                'changed' => collect($this->preview ?? [])->where('changed', true)->count(),
                'before' => collect($this->preview ?? [])->sum(fn ($r) => $r['cents'][0]),
                'after' => collect($this->preview ?? [])->sum(fn ($r) => $r['cents'][1]),
            ],
        ];
    }
}
