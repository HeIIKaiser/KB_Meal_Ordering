<?php

namespace App\Filament\Pages;

use App\Domain\Menus\MenuImport;
use App\Domain\Menus\MenuWriter;
use App\Enums\NavGroup;
use App\Enums\Role;
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

/** Menu import: upload → preview (actions, row errors, affected employees) → confirm. */
class ImportMenus extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.import-menus';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Menus;

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Import jedálneho lístka';

    protected static ?string $navigationLabel = 'Import lístka';

    protected static ?string $slug = 'menu/import';

    #[Validate(['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'], message: [
        'required' => 'Vyberte súbor.',
        'mimes' => 'Súbor musí byť XLSX alebo CSV.',
        'max' => 'Súbor je väčší ako 5 MB.',
    ])]
    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin, Role::Kitchen);
    }

    public function analyze(MenuImport $import): void
    {
        $this->validate();
        $this->preview = null;

        try {
            $result = $import->preview($this->file->getRealPath(), $this->file->getClientOriginalName());
            $this->preview = [
                'plan' => $result['plan']->all(),
                'errors' => $result['errors'],
                'impact' => MenuWriter::impact($result['plan'])->all(),
            ];
        } catch (Throwable $e) {
            report($e);
            $this->addError('file', $e instanceof \RuntimeException ? $e->getMessage() : 'Súbor sa nepodarilo prečítať.');
        }
    }

    public function commit(MenuWriter $writer)
    {
        abort_unless(static::canAccess() && $this->preview, 403);

        $changed = $writer->apply(collect($this->preview['plan']));

        Notification::make()->title('Jedálny lístok importovaný')->body("Zmenených položiek: $changed.")->success()->send();

        $firstDate = collect($this->preview['plan'])->min('date');

        return redirect(MenuWeek::getUrl(['tyzden' => $firstDate]));
    }

    public function resetImport(): void
    {
        $this->reset(['file', 'preview']);
    }
}
