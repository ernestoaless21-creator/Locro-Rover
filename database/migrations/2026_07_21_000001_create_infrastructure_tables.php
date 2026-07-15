<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 15: inventario historico de infraestructura y gestion de prestamos.
     *
     * Mismo patron que Fase 14 (compras): catalogo reutilizable
     * (infrastructure_items) sin year_id ni team, y datos concretos por
     * edicion (infrastructure_inventory_items) con su propio year_id, para
     * preservar el historial sin sobrescribirlo. El equipo Infraestructura se
     * identifica por slug via las rutas (igual que Compras), nunca por un ID
     * de equipo hardcodeado.
     *
     * infrastructure_loans tambien tiene year_id propio (un prestamo
     * pertenece a la edicion en la que se pidio) para que, al importar de una
     * edicion anterior, los prestamos NUNCA reaparezcan como activos por
     * accidente en la edicion nueva.
     */
    public function up(): void
    {
        Schema::create('infrastructure_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('infrastructure_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->foreignId('infrastructure_item_id')->constrained('infrastructure_items')->restrictOnDelete();

            // "cantidad propia disponible" ya incluye fisicamente las unidades
            // a reparar (ver seccion 5 del prompt de la fase): propias_utiles
            // = own_available_quantity - own_to_repair_quantity.
            $table->unsignedSmallInteger('needed_quantity')->default(0);
            $table->unsignedSmallInteger('own_available_quantity')->default(0);
            $table->unsignedSmallInteger('own_to_repair_quantity')->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['year_id', 'infrastructure_item_id']);
        });

        Schema::create('infrastructure_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->foreignId('infrastructure_item_id')->constrained('infrastructure_items')->restrictOnDelete();

            $table->unsignedSmallInteger('quantity');
            $table->string('lender'); // "Prestado por": texto libre, sin entidad de prestamistas.

            // Status separado de la fecha (igual que ScheduleActivity en Fase
            // 13): un prestamo puede marcarse devuelto SIN conocer la fecha
            // exacta, sin inventar precision que no existe.
            $table->string('status', 20)->default('pending');
            $table->date('returned_at')->nullable();

            $table->text('notes')->nullable(); // identificacion fisica y otros detalles.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_loans');
        Schema::dropIfExists('infrastructure_inventory_items');
        Schema::dropIfExists('infrastructure_items');
    }
};
