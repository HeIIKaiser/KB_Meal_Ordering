<?php

namespace App\Filament\Pages;

use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\TicketAdmin;
use App\Domain\Tickets\TicketAllocation;
use App\Domain\Tickets\TicketRoster;
use App\Enums\NavGroup;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Models\ClosedMonth;
use App\Models\Order;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * „Lístky na mesiac“: ticket count per ticket-mode group for a month (one Uložiť),
 * individual overrides per employee, and closing / reopening the month.
 * Payroll can read everything; only the admin changes it.
 */
class TicketMonth extends Page
{
    protected string $view = 'filament.pages.ticket-month';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Lístky na mesiac';

    protected static ?string $navigationLabel = 'Lístky na mesiac';

    protected static ?string $slug = 'listky-na-mesiac';

    #[Url(as: 'mesiac')]
    public string $month = '';

    /** @var array<int|string, int|string|null> group id → tickets */
    public array $counts = [];

    /** @var array<int|string, int|string|null> employee id → override ('' = group count) */
    public array $overrides = [];

    /** Preview of the pending save (shown for confirmation when orders change). */
    #[Locked]
    public ?array $pending = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin, Role::Payroll);
    }

    public function mount(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::current();
        }
        $this->loadMonth();
    }

    public function updatedMonth(): void
    {
        if (! Month::valid($this->month)) {
            $this->month = Month::current();
        }
        $this->loadMonth();
    }

    public function previousMonth(): void
    {
        $this->month = Month::previous($this->month);
        $this->loadMonth();
    }

    public function nextMonth(): void
    {
        $this->month = Month::next($this->month);
        $this->loadMonth();
    }

    public function canEdit(): bool
    {
        return (bool) auth()->user()?->isAdmin() && ! app(ClosedMonths::class)->isClosed($this->month);
    }

    /** Check the change; save straight away when no ordered price changes, else ask first. */
    public function review(TicketAdmin $admin): void
    {
        abort_unless($this->canEdit(), 403);
        [$counts, $overrides] = $this->validated();

        try {
            $preview = $admin->preview($this->month, $counts, $overrides, auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($preview->isEmpty()) {
            $this->persist($admin, $counts, $overrides);

            return;
        }

        $this->pending = ['text' => MealGroupResource::describe($preview)];
    }

    public function confirmSave(TicketAdmin $admin): void
    {
        abort_unless($this->canEdit() && $this->pending, 403);
        [$counts, $overrides] = $this->validated();
        $this->persist($admin, $counts, $overrides);
    }

    public function cancelPending(): void
    {
        $this->pending = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeMonth')
                ->label('Uzavrieť mesiac')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->visible(fn () => auth()->user()?->isAdmin() && ! app(ClosedMonths::class)->isClosed($this->month))
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Uzavrieť '.Month::label($this->month).'?')
                ->modalDescription('Po uzavretí sa v tomto mesiaci nedajú meniť objednávky, lístky ani typy cien (pre nikoho). Exporty budú mať konečné ceny. Mesiac sa dá znova otvoriť s uvedením dôvodu.')
                ->modalSubmitActionLabel('Uzavrieť')
                ->action(function () {
                    app(ClosedMonths::class)->close($this->month, auth()->user());
                    $this->pending = null;
                    Notification::make()->title('Mesiac uzavretý')->success()->send();
                }),
            Action::make('reopenMonth')
                ->label('Otvoriť mesiac')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->visible(fn () => auth()->user()?->isAdmin() && app(ClosedMonths::class)->isClosed($this->month))
                ->schema([TextInput::make('reason')->label('Dôvod')->required()->maxLength(255)])
                ->modalHeading(fn () => 'Znova otvoriť '.Month::label($this->month))
                ->action(function (array $data) {
                    app(ClosedMonths::class)->reopen($this->month, auth()->user(), $data['reason']);
                    Notification::make()->title('Mesiac je znova otvorený')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $roster = app(TicketRoster::class);
        $groups = $roster->ticketGroups($this->month);
        $employees = $roster->employees($this->month);
        $saved = TicketMonthCount::query()->where('month', $this->month)->pluck('tickets', 'meal_group_id');
        $overrides = TicketOverride::query()->where('month', $this->month)->get()->keyBy('employee_id');
        $used = Order::query()
            ->where('status', OrderStatus::Active->value)
            ->where('pricing_mode', PricingMode::Tickets->value)
            ->whereBetween('date', [Month::first($this->month)->toDateString(), Month::last($this->month)->toDateString()])
            ->selectRaw('employee_id, COUNT(*) as n')->groupBy('employee_id')->pluck('n', 'employee_id');

        $rows = $employees->map(function ($r) use ($saved, $overrides, $used) {
            $group = $r['group'];
            $override = $overrides[$r['employee']->id] ?? null;
            $allocated = $override?->tickets ?? $saved[$group->id] ?? $group->default_tickets;
            $n = (int) ($used[$r['employee']->id] ?? 0);

            return [
                'employee' => $r['employee'],
                'group' => $group->name,
                'allocated' => $allocated,
                'used' => $n,
                'left' => $allocated - $n,
                'source' => $override?->source,
            ];
        });

        $closed = ClosedMonth::query()->where('month', $this->month)->with('closedBy')->first();

        return [
            'label' => Month::label($this->month),
            'groups' => $groups,
            'saved' => $saved,
            'rows' => $rows,
            'closed' => $closed,
            'editable' => $this->canEdit(),
            'isPast' => Month::last($this->month)->isPast(),
        ];
    }

    private function loadMonth(): void
    {
        $this->pending = null;
        $allocation = app(TicketAllocation::class);
        $this->counts = app(TicketRoster::class)->ticketGroups($this->month)
            ->mapWithKeys(fn ($g) => [$g->id => $allocation->prefill($g, $this->month)])->all();
        $this->overrides = TicketOverride::query()->where('month', $this->month)->pluck('tickets', 'employee_id')
            ->map(fn ($t) => (string) $t)->all();
    }

    /** @return array{0: array<int, int>, 1: array<int, int|null>} */
    private function validated(): array
    {
        $this->validate([
            'counts.*' => ['required', 'integer', 'min:0', 'max:999'],
            'overrides.*' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'counts.*' => 'Počet lístkov skupiny musí byť číslo 0 – 999.',
            'overrides.*' => 'Individuálny počet musí byť číslo 0 – 999 alebo prázdne.',
        ]);

        $groupIds = app(TicketRoster::class)->ticketGroups($this->month)->pluck('id')->all();
        $counts = collect($this->counts)->only($groupIds)->map(fn ($v) => (int) $v)->all();

        $employeeIds = app(TicketRoster::class)->employees($this->month)->map(fn ($r) => $r['employee']->id)->all();
        $existing = TicketOverride::query()->where('month', $this->month)->pluck('employee_id')->all();
        $overrides = [];
        foreach (array_unique([...$employeeIds, ...$existing]) as $id) {
            $v = $this->overrides[$id] ?? '';
            $overrides[$id] = ($v === '' || $v === null) ? null : (int) $v;
        }

        return [$counts, $overrides];
    }

    private function persist(TicketAdmin $admin, array $counts, array $overrides): void
    {
        try {
            $result = $admin->save($this->month, $counts, $overrides, auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->loadMonth();
        Notification::make()->title('Lístky uložené')->body(MealGroupResource::describe($result))->success()->send();
    }
}
