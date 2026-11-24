<?php

namespace App\Filament\Pages;

use App\Domain\Employees\EmployeeImport;
use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\MealGroupService;
use App\Enums\NavGroup;
use App\Enums\Role;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;
use UnitEnum;

/** Employee import: upload → preview (per-row result and errors) → confirm. */
class ImportEmployees extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.import-employees';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::People;

    protected static ?string $title = 'Import zamestnancov';

    protected static ?string $slug = 'employees/import';

    #[Validate(['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'], message: [
        'required' => 'Vyberte súbor.',
        'mimes' => 'Súbor musí byť XLSX alebo CSV.',
        'max' => 'Súbor je väčší ako 5 MB.',
    ])]
    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $preview = null;

    public bool $deactivateMissing = false;

    /** Optional start date for ALL group changes in the file (empty = today / 1st of next month). */
    public ?string $groupFrom = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function analyze(EmployeeImport $import): void
    {
        $this->validate();
        $this->preview = null;

        try {
            $this->preview = $import->preview($this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (Throwable $e) {
            $this->addError('file', $e instanceof \RuntimeException ? $e->getMessage() : 'Súbor sa nepodarilo prečítať.');
        }
    }

    public function saveImport(EmployeeImport $import)
    {
        abort_unless(static::canAccess() && $this->preview, 403);

        $groupFrom = filled($this->groupFrom) ? $this->groupFrom : null;
        if ($groupFrom !== null) {
            try {
                $groupFrom = LocalTime::date($groupFrom)->toDateString();
                app(MealGroupService::class)->assertDateOpen($groupFrom);
            } catch (OrderingException $e) {
                $this->addError('groupFrom', $e->getMessage());

                return null;
            } catch (Throwable) {
                $this->addError('groupFrom', 'Neplatný dátum.');

                return null;
            }
        }

        $result = $import->apply($this->preview, $this->deactivateMissing, auth()->user(), $groupFrom);

        Notification::make()
            ->title('Import dokončený')
            ->body("Nových: {$result['created']} (PIN vygenerovaný), upravených: {$result['updated']}, deaktivovaných: {$result['deactivated']}, zmien skupiny: {$result['groups']}.")
            ->success()
            ->persistent()
            ->actions($result['created_ids'] ? [
                Action::make('print')
                    ->label('Vytlačiť lístky nových ('.$result['created'].')')
                    ->button()
                    ->url(EmployeeResource::slipsUrl($result['created_ids']), shouldOpenInNewTab: true),
            ] : [])
            ->send();

        return redirect(EmployeeResource::getUrl());
    }

    public function clearGroupFrom(): void
    {
        $this->groupFrom = null;
    }

    /** "1. 12. 2026": the override date if valid, else $default. */
    public function groupFromLabel(string $default): string
    {
        try {
            return LocalTime::date(filled($this->groupFrom) ? $this->groupFrom : $default)->format('j. n. Y');
        } catch (Throwable) {
            return LocalTime::date($default)->format('j. n. Y');
        }
    }

    public function resetImport(): void
    {
        $this->reset(['file', 'preview', 'deactivateMissing', 'groupFrom']);
    }

    public function getBreadcrumbs(): array
    {
        return [EmployeeResource::getUrl() => 'Zamestnanci', 'Import'];
    }
}
