<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function deactivate(): void
    {
        $this->forceFill([
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
        ])->save();
    }

    /**
     * Acceso a información financiera global:
     * gastos, ingresos, resultado neto y recaudación por medio de pago.
     * Delega en el permiso real 'finanzas.ver' (Fase 5A: antes chequeaba
     * roles hardcodeados, lo cual podia divergir del seeder de permisos si
     * algun dia cambia sin tocar este metodo).
     */
    public function canViewFinancials(): bool
    {
        return $this->can('finanzas.ver');
    }

    public function teamSlug(): ?string
    {
        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            if ($this->hasRole($team) || $this->hasRole("jefe_{$team}")) {
                return $team;
            }
        }

        return null;
    }
}