<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('year_id')->constrained('years')->restrictOnDelete();
            $table->foreignId('rover_id')->nullable()->constrained('users')->nullOnDelete(); // vendedor/rover responsable

            // Totales calculados y persistidos (se recalculan via Observer/Service al tocar order_items o payments)
            $table->unsignedInteger('total_portions')->default(0); // cantidad fisica total de porciones
            $table->decimal('total_amount', 12, 2)->default(0); // importe total del pedido
            $table->decimal('total_paid', 12, 2)->default(0); // total efectivamente pagado (suma de payments)
            $table->decimal('balance_due', 12, 2)->default(0); // saldo pendiente = total_amount - total_paid

            $table->enum('status', ['pendiente', 'confirmado', 'cancelado'])->default('pendiente');
            $table->enum('withdrawal_status', ['no_retirado', 'retirado', 'parcial'])->default('no_retirado');
            $table->timestamp('withdrawn_at')->nullable();

            $table->text('observations')->nullable(); // observaciones del PEDIDO (distinto de general_notes del cliente)

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['year_id', 'rover_id']);
            $table->index(['year_id', 'status']);
            $table->index(['year_id', 'withdrawal_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
