<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique(); // ej: 2026
            $table->string('label')->nullable(); // ej: "Locro 2026 - 40 anos"
            $table->decimal('portion_price', 12, 2)->default(0); // precio estandar de la porcion vigente
            $table->boolean('is_active')->default(false); // edicion actualmente en curso
            $table->date('sale_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Solo puede haber un anio activo a la vez; se controla a nivel de aplicacion (Service),
        // no con constraint de DB, para permitir reactivar años pasados si hiciera falta.
    }

    public function down(): void
    {
        Schema::dropIfExists('years');
    }
};
