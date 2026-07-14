<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 4 (retiro individual/masivo): faltaba registrar QUIEN marco el retiro
     * y una observacion opcional. withdrawn_at ya existia (migracion 000004).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawn_at')
                ->constrained('users')->nullOnDelete();
            $table->text('withdrawal_notes')->nullable()->after('withdrawn_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn('withdrawal_notes');
        });
    }
};
