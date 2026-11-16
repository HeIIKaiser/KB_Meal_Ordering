<?php

namespace App\Filament\Pages;

use App\Enums\NavGroup;
use App\Enums\Role;
use App\Support\IpList;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

/** Bridge token (shown once, stored hashed), IP allowlist and the meal thresholds. Admin only. */
class AttendanceSettings extends Page
{
    protected string $view = 'filament.pages.attendance-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Dochádzka – synchronizácia';

    protected static ?string $navigationLabel = 'Dochádzka – synchronizácia';

    protected static ?string $slug = 'dochadzka-nastavenia';

    public string $oneMeal = '4:00';

    public string $twoMeals = '10:00';

    public string $allowlist = '';

    /** The new token, shown exactly once after generating. */
    #[Locked]
    public ?string $newToken = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public function mount(Settings $settings): void
    {
        $this->oneMeal = self::hm($settings->int(Settings::ATTENDANCE_ONE_MEAL_SECONDS));
        $this->twoMeals = self::hm($settings->int(Settings::ATTENDANCE_TWO_MEALS_SECONDS));
        $this->allowlist = (string) $settings->get(Settings::ATTENDANCE_IP_ALLOWLIST);
    }

    public function save(Settings $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate([
            'oneMeal' => ['required', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'twoMeals' => ['required', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'allowlist' => ['nullable', 'string', 'max:500'],
        ], ['oneMeal.*' => 'Zadajte čas v tvare H:MM.', 'twoMeals.*' => 'Zadajte čas v tvare H:MM.']);

        $one = self::seconds($this->oneMeal);
        $two = self::seconds($this->twoMeals);
        if ($two <= $one) {
            $this->addError('twoMeals', 'Hranica pre 2 jedlá musí byť vyššia ako pre 1 jedlo.');

            return;
        }
        if ($bad = IpList::invalid($this->allowlist)) {
            $this->addError('allowlist', 'Neplatné adresy: '.implode(', ', $bad));

            return;
        }

        $settings->set(Settings::ATTENDANCE_ONE_MEAL_SECONDS, $one);
        $settings->set(Settings::ATTENDANCE_TWO_MEALS_SECONDS, $two);
        $settings->set(Settings::ATTENDANCE_IP_ALLOWLIST, trim($this->allowlist) ?: null);

        Notification::make()->title('Nastavenia uložené')->body('Nové hranice sa použijú pri ďalšej synchronizácii (alebo v noci).')->success()->send();
    }

    public function generateToken(Settings $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $token = Str::random(48);
        $settings->set(Settings::ATTENDANCE_TOKEN_HASH, hash('sha256', $token));
        activity('settings')->causedBy(auth()->user())->event('attendance_token_rotated')->log('Nový token pre synchronizáciu dochádzky');
        $this->newToken = $token;
    }

    protected function getViewData(): array
    {
        return ['hasToken' => (bool) app(Settings::class)->get(Settings::ATTENDANCE_TOKEN_HASH), 'url' => url('/api/attendance-sync')];
    }

    public static function hm(int $seconds): string
    {
        return intdiv($seconds, 3600).':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    }

    public static function seconds(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 3600 + $m * 60;
    }
}
