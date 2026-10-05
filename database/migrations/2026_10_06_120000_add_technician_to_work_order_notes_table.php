<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who typed a THMP field note. The crew shares one vendor login, so the note
 * row could only name the company; the note dialog now asks the technician to
 * pick their name and it is kept here.
 *
 * technician_id is a plain nullable column (no FK), like
 * service_schedules.technician_id: a note outlives roster edits.
 * technician_name is the name as it read when the note was saved, so the note
 * keeps its author after the technician is renamed or removed.
 *
 * Guarded both ways — a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_order_notes')) {
            return;
        }

        if (! Schema::hasColumn('work_order_notes', 'technician_id')) {
            Schema::table('work_order_notes', function (Blueprint $table) {
                $table->unsignedBigInteger('technician_id')->nullable();
            });
        }

        if (! Schema::hasColumn('work_order_notes', 'technician_name')) {
            Schema::table('work_order_notes', function (Blueprint $table) {
                $table->string('technician_name', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_order_notes')) {
            return;
        }

        foreach (['technician_name', 'technician_id'] as $column) {
            if (Schema::hasColumn('work_order_notes', $column)) {
                Schema::table('work_order_notes', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
