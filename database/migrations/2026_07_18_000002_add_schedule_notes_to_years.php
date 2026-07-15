<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->text('schedule_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn('schedule_notes');
        });
    }
};
