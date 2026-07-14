<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Year extends Model
{
    use HasFactory;

    protected $fillable = [
        'year', 'label', 'portion_price', 'promo_unit_price', 'amount_for_promo',
        'is_active', 'sale_date', 'notes', 'made_portions', 'event_type',
        'sauce_portions_per_block', 'sauce_units_per_block',
        'sales_goal_global', 'sales_goal_individual_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sale_date' => 'date',
        'portion_price' => 'decimal:2',
        'promo_unit_price' => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class);
    }

    public function losses(): HasMany
    {
        return $this->hasMany(Loss::class);
    }

    public function clientAssignments(): HasMany
    {
        return $this->hasMany(ClientAssignment::class);
    }

    /**
     * Representacion "publica" del anio, sin los campos considerados
     * sensibles a nivel de produccion (Fase 6A, seccion 11): made_portions no
     * debe viajar en el payload Inertia para un usuario operativo sin
     * 'produccion.ver' (ej. Compras/Infraestructura/Publicidad). Se usa en
     * HandleInertiaRequests y DashboardController en vez de mandar el modelo
     * completo cuando el usuario no tiene ese permiso.
     */
    public function toPublicArray(bool $canViewProduction): array
    {
        $data = $this->only([
            'id', 'year', 'label', 'is_active', 'sale_date', 'notes', 'event_type',
            'portion_price', 'promo_unit_price', 'amount_for_promo',
            'sauce_portions_per_block', 'sauce_units_per_block',
            'sales_goal_global', 'sales_goal_individual_default',
            'created_at', 'updated_at',
        ]);

        if ($canViewProduction) {
            $data['made_portions'] = $this->made_portions;
        }

        return $data;
    }

    /**
     * Marca este anio como activo y desactiva todos los demas.
     * Unica forma soportada de cambiar el anio activo (evita 2 anios activos a la vez).
     */
    public function activate(): void
    {
        static::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
