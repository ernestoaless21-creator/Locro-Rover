<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splits the single `actual_time` TIMESTAMP column into two independent,
     * optional pieces of information: `actual_date` (DATE) and `actual_time`
     * (TIME). This lets an activity record "realizada" with any of three
     * precision levels — nothing, date only, or date + time — without ever
     * faking a 00:00 clock time to stand in for "unknown".
     */
    public function up(): void
    {
        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->date('actual_date')->nullable()->after('status');
            $table->time('actual_time_new')->nullable()->after('actual_date');
        });

        DB::table('schedule_activities')
            ->whereNotNull('actual_time')
            ->orderBy('id')
            ->select('id', 'actual_time')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $moment = Carbon::parse($row->actual_time);
                    DB::table('schedule_activities')->where('id', $row->id)->update([
                        'actual_date'     => $moment->toDateString(),
                        'actual_time_new' => $moment->format('H:i:s'),
                    ]);
                }
            });

        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->dropColumn('actual_time');
        });

        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->renameColumn('actual_time_new', 'actual_time');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->timestamp('actual_moment')->nullable()->after('status');
        });

        DB::table('schedule_activities')
            ->whereNotNull('actual_date')
            ->orderBy('id')
            ->select('id', 'actual_date', 'actual_time')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $time   = $row->actual_time ?? '00:00:00';
                    $moment = Carbon::parse("{$row->actual_date} {$time}");
                    DB::table('schedule_activities')->where('id', $row->id)->update([
                        'actual_moment' => $moment,
                    ]);
                }
            });

        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->dropColumn(['actual_date', 'actual_time']);
        });

        Schema::table('schedule_activities', function (Blueprint $table) {
            $table->renameColumn('actual_moment', 'actual_time');
        });
    }
};
