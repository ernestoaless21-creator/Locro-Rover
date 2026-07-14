<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Porciones regaladas/donadas (Fase 5B). Registro independiente, NO es un
     * pedido: no tiene client_id, no tiene importe, no tiene pagos.
     *
     * 'recipient_name' es texto libre (nombre o descripcion de a quien se le
     * regalo, ej "Carsten" o "Cocina - ayudantes"), deliberadamente NO un FK a
     * clients: el pedido explicito del usuario es no exigir crear un cliente
     * completo para esto.
     *
     * year_id usa restrictOnDelete (igual que orders.year_id, ver
     * create_orders_table): un Year jamas deberia poder borrarse si tiene
     * regalos registrados, para no perder trazabilidad historica. Hoy no
     * existe ninguna ruta que borre un Year, pero se protege igual a nivel
     * de esquema.
     */
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->restrictOnDelete();
            $table->string('recipient_name');
            $table->unsignedInteger('quantity');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['year_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
