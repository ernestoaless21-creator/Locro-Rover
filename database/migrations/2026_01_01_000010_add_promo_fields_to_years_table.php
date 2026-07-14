<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confirmado en Parameters.js de la app anterior: cada anio define
     * unit_price (ya existe como portion_price), promo_unit_price y
     * amount_for_promo (cantidad de porciones que entran en la promo).
     * Ej: amount_for_promo=2, promo_unit_price=20000 => "2 porciones por $20.000".
     */
    public function up(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->decimal('promo_unit_price', 12, 2)->nullable()->after('portion_price');
            $table->unsignedInteger('amount_for_promo')->nullable()->after('promo_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn(['promo_unit_price', 'amount_for_promo']);
        });
    }
};
