<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Porciones perdidas (Fase 5B). Registro independiente, NO es un pedido
     * ni un regalo: no tiene destinatario, no tiene importe, no tiene pagos.
     *
     * 'reason' es el motivo/observacion libre (ej "Se cayo una olla"),
     * nullable porque el pedido del usuario lo marca como opcional.
     *
     * year_id usa restrictOnDelete por el mismo motivo que en gifts (ver
     * create_gifts_table): preservar trazabilidad historica.
     */
    public function up(): void
    {
        Schema::create('losses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['year_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('losses');
    }
};
