<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->enum('type', ['normal', 'regalo', 'promocion', 'personalizado'])->default('normal');
            $table->string('description')->nullable(); // ej: "Promocion 2x1", "Regalo cumpleanos"

            $table->unsignedInteger('quantity'); // cantidad fisica de porciones de esta linea
            $table->decimal('unit_price', 12, 2)->default(0); // precio unitario de referencia (informativo en promos)
            $table->decimal('line_total', 12, 2)->default(0); // importe real de la linea (lo que efectivamente suma al total)

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
