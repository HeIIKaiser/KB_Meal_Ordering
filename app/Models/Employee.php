<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
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

    protected $hidden = ['pin_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin_changed_at' => 'datetime',
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

    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->pin_changed_at = now();
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
