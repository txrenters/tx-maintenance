<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A service schedule can name more than one technician: THMP sometimes sends
 * two on a single visit. The pivot carries every pick; the older single
 * service_schedules.technician_id column stays in place and keeps mirroring
 * the first pick, so a code revert loses nothing.
 *
 * No foreign keys, matching technician_id: a schedule outlives roster edits.
 * Guarded both ways and the backfill converges, so a re-run after a partial
 * failure (or a startup.sh double-run) is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_schedule_technicians')) {
            Schema::create('service_schedule_technicians', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('service_schedule_id')->index();
                $table->unsignedBigInteger('technician_id')->index();
                $table->timestamps();

                $table->unique(['service_schedule_id', 'technician_id'], 'service_schedule_technicians_unique');
            });
        }

        if (! Schema::hasTable('service_schedules') || ! Schema::hasColumn('service_schedules', 'technician_id')) {
            return;
        }

        // Carry the single picks already made into the pivot, skipping any
        // row a previous attempt copied.
        $now = now();

        DB::table('service_schedules')
            ->whereNotNull('technician_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('service_schedule_technicians')
                    ->whereColumn('service_schedule_technicians.service_schedule_id', 'service_schedules.id')
                    ->whereColumn('service_schedule_technicians.technician_id', 'service_schedules.technician_id');
            })
            ->orderBy('id')
            ->select(['id', 'technician_id'])
            ->chunk(500, function ($schedules) use ($now) {
                DB::table('service_schedule_technicians')->insert(
                    $schedules->map(fn ($schedule): array => [
                        'service_schedule_id' => $schedule->id,
                        'technician_id' => $schedule->technician_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        // The mirrored technician_id column keeps the first pick, so dropping
        // the pivot returns the schedule to its single-technician shape.
        if (Schema::hasTable('service_schedule_technicians')) {
            Schema::drop('service_schedule_technicians');
        }
    }
};
