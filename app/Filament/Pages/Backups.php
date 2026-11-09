<?php

namespace App\Filament\Pages;

use App\Domain\Backup\DatabaseBackup;
use App\Enums\NavGroup;
use App\Enums\Role;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/** Database backups (admin): list, download, create now. */
class Backups extends Page
{
    protected string $view = 'filament.pages.backups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Zálohy databázy';

    protected static ?string $navigationLabel = 'Zálohy';

    protected static ?string $slug = 'zalohy';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function backupNow(DatabaseBackup $backup): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $path = $backup->create();
            activity('backups')->causedBy(auth()->user())->event('created')->log('Záloha vytvorená: '.basename($path));
            Notification::make()->title('Záloha vytvorená')->body(basename($path))->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Zálohu sa nepodarilo vytvoriť')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $backup = app(DatabaseBackup::class);
        $latest = $backup->latest();

        return [
            'files' => $backup->list(),
            'stale' => ! $latest || $latest['mtime'] < now()->subHours(26)->getTimestamp(),
            'directory' => str_replace(base_path().'/', '', $backup->directory()),
        ];
    }
}
