<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "take_away" (retira en mano / no requiere reparto) es un campo real y distinto
     * de withdrawal_status (si ya fue retirado o no). Se preserva tal cual.
     *
     * "client_observations" en la app anterior es una tabla propia con year_id + client_id,
     * es decir observaciones del cliente PARA ese anio en particular (no del pedido puntual,
     * no genericas para siempre). Se modela como tabla independiente para preservar ese
     * comportamiento y su historial completo.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('take_away')->default(false)->after('withdrawal_status');
        });

        Schema::create('client_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->text('observation');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_observations');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('take_away');
        });
    }
};
