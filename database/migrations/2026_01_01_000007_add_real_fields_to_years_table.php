<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajuste segun datos reales confirmados en la app anterior (Inertia page-props):
     * - "made_portions": contador de porciones efectivamente cocinadas (distinto de vendidas).
     * - "event_type": la app anterior vende distintos productos segun el anio (locro vs pastelitos).
     *   Se generaliza para futuras ediciones (ej: podria haber otro producto en el futuro).
     */
    public function up(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->unsignedInteger('made_portions')->default(0)->after('portion_price');
            $table->string('event_type')->default('locro')->after('made_portions'); // locro | pastelitos | otro
        });
    }

    public function down(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn(['made_portions', 'event_type']);
        });
    }
};
