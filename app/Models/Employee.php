<?php

namespace App\Models;

use App\Domain\Employees\PinIssuer;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An employee who orders meals. Logs in with osobné číslo + PIN.
 */
class Employee extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, LogsActivity;

    protected $fillable = [
        'osobne_cislo', 'first_name', 'last_name', 'department', 'is_active', 'user_id',
    ];

    protected $hidden = ['pin_hash', 'pin_plain', 'remember_token'];

    /** Who set the current PIN. */
    public const PIN_BY_SYSTEM = 'system';     // generated automatically on create/import

    public const PIN_BY_ADMIN = 'admin';       // Reset PIN / CLI

    public const PIN_BY_EMPLOYEE = 'employee'; // changed by the employee → not printable

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin_changed_at' => 'datetime',
            'slip_printed_at' => 'datetime',
            'pin_plain' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->pin_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'pin_hash';
    }

    protected static function booted(): void
    {
        // Every new employee gets a login right away (form, import, CLI).
        static::creating(function (Employee $employee) {
            if ($employee->pin_hash === null) {
                $employee->setPin(PinIssuer::generate(), self::PIN_BY_SYSTEM);
            }
        });
    }

    /**
     * Set a new PIN. PINs set by the system/admin are also kept encrypted so the
     * access slip can be reprinted; a PIN chosen by the employee is never stored readable.
     */
    public function setPin(string $pin, string $by = self::PIN_BY_ADMIN): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->pin_plain = $by === self::PIN_BY_EMPLOYEE ? null : $pin;
        $this->pin_set_by = $by;
        $this->pin_changed_at = now();
    }

    /** Current PIN if it can be printed, else null (changed by the employee, unknown, or APP_KEY changed). */
    public function printablePin(): ?string
    {
        try {
            return $this->pin_plain;
        } catch (DecryptException) {
            return null;
        }
    }

    /** Slovak status for the admin list: "na lístku" / "zmenil zamestnanec" / "neznámy" / "chýba". */
    public function pinStatus(): string
    {
        return match (true) {
            $this->pin_hash === null => 'chýba',
            $this->pin_set_by === self::PIN_BY_EMPLOYEE => 'zmenil zamestnanec',
            $this->printablePin() !== null => 'na lístku',
            default => 'neznámy',
        };
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    /** "Priezvisko Meno" — the sort/display order used on kitchen lists and exports. */
    public function fullNameReversed(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeAlphabetical(Builder $query): void
    {
        $query->orderBy('last_name')->orderBy('first_name')->orderBy('osobne_cislo');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['osobne_cislo', 'first_name', 'last_name', 'department', 'is_active', 'user_id', 'pin_changed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employees');
    }
}
