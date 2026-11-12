<?php

namespace App\Filament\Pages;

use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\FinalTicketImport;
use App\Domain\Tickets\Month;
use App\Enums\NavGroup;
use App\Enums\Role;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;
use UnitEnum;

/** Month-end import of the final ticket counts: upload → preview → save (reprice) → close the month. */
class ImportFinalTickets extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.import-final-tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Konečné lístky (import)';

    protected static ?string $navigationLabel = 'Konečné lístky (import)';

    protected static ?string $slug = 'konecne-listky';

    public string $month = '';

    #[Validate(['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'], message: [
        'required' => 'Vyberte súbor.',
        'mimes' => 'Súbor musí byť XLSX alebo CSV.',
        'max' => 'Súbor je väčší ako 5 MB.',
    ])]
    public ?TemporaryUploadedFile $file = null;

    #[Locked]
    public ?array $preview = null;

    /** Month just saved — offers „Uzavrieť mesiac“. */
    #[Locked]
    public ?string $saved = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function mount(): void
    {
        $today = LocalTime::today();
        $this->month = ($today->day <= 15 ? $today->subMonthNoOverflow() : $today)->format('Y-m');
    }

    public function updatedMonth(): void
    {
        $this->preview = null;
        $this->saved = null;
    }

    public function analyze(FinalTicketImport $import): void
    {
        $this->validate();
        $this->validate(['month' => ['required', 'date_format:Y-m']], ['month.*' => 'Zvoľte mesiac.']);
        $this->preview = null;
        $this->saved = null;

        if (app(ClosedMonths::class)->isClosed($this->month)) {
            $this->addError('month', 'Mesiac '.Month::label($this->month).' je uzavretý. Ak treba lístky opraviť, najprv ho otvorte (Lístky na mesiac).');

            return;
        }

        try {
            $this->preview = $import->preview($this->file->getRealPath(), $this->file->getClientOriginalName(), $this->month, auth()->user());
        } catch (OrderingException $e) {
            $this->addError('file', $e->getMessage());
        } catch (Throwable $e) {
            $this->addError('file', $e instanceof \RuntimeException ? $e->getMessage() : 'Súbor sa nepodarilo prečítať.');
        }
    }

    public function saveImport(FinalTicketImport $import): void
    {
        abort_unless(static::canAccess() && $this->preview && $this->preview['rows'], 403);

        try {
            $result = $import->apply($this->preview, auth()->user());
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->saved = $this->preview['month'];
        $this->preview = null;
        $this->file = null;
        Notification::make()->title('Konečné lístky uložené')->body(MealGroupResource::describe($result))->success()->send();
    }

    public function closeMonth(ClosedMonths $closed): void
    {
        abort_unless(static::canAccess() && $this->saved, 403);
        $closed->close($this->saved, auth()->user());
        Notification::make()->title('Mesiac '.Month::label($this->saved).' uzavretý')->success()->send();
        $this->saved = null;
    }

    public function resetImport(): void
    {
        $this->reset(['file', 'preview', 'saved']);
    }

    protected function getViewData(): array
    {
        return ['label' => Month::valid($this->month) ? Month::label($this->month) : ''];
    }
}
