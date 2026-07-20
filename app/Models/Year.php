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
        'is_active', 'sale_date', 'notes', 'schedule_notes', 'made_portions', 'event_type',
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

    /**
     * Fase 19: unica fuente de verdad de "esta edicion se puede modificar
     * ahora mismo". Cualquier edicion que no sea la activa pasa a ser de
     * solo lectura para todos salvo quien tenga 'anios.gestionar' (ver
     * User::canEditHistoricalEditions). Usado desde App\Policies\YearPolicy
     * (Gate::authorize('mutate', $year)) en cada controlador que muta un
     * registro perteneciente a una edicion -- NUNCA se debe repetir esta
     * condicion inline en un controlador, siempre pasar por aca.
     */
    public function isEditableBy(User $user): bool
    {
        return $this->is_active || $user->canEditHistoricalEditions();
    }

    /**
     * Fase 21 (correccion de bug): unica fuente de verdad para el array
     * MINIMO de campos que necesita CUALQUIER pantalla que reciba un prop
     * 'year' de identificacion (selector de edicion, y sobre todo
     * HistoricalEditionBanner.vue / useEditableYear.js -- ambos leen
     * `year.is_active` para decidir si la edicion es de solo lectura).
     *
     * Bug real detectado: varios controladores (Meetings, Cronograma, Mi
     * Equipo, Compras, Infraestructura, Publicidad, Logistica) armaban este
     * mismo array a mano con $year->only('id', 'year', 'label') y se
     * olvidaban 'is_active'. Con ese campo ausente, `Boolean(year?.is_active)`
     * en useEditableYear.js evalua a `false` SIEMPRE (edicion activa
     * incluida), y `v-if="!year.is_active"` en HistoricalEditionBanner.vue
     * es SIEMPRE true: la edicion activa terminaba marcada como historica de
     * solo lectura para cualquier usuario sin 'anios.gestionar'. No era un
     * bug de logica (Year::isEditableBy/YearPolicy::mutate ya eran
     * correctos), sino de datos incompletos viajando al frontend.
     *
     * Todo controlador que arme un prop 'year' (o 'targetYear'/similar) para
     * una pantalla Inertia debe usar este metodo en vez de repetir su propio
     * ->only(...), para que agregar/sacar un campo de esta lista quede
     * centralizado en un unico lugar.
     */
    public function toBasicArray(): array
    {
        return $this->only(['id', 'year', 'label', 'is_active']);
    }
}
