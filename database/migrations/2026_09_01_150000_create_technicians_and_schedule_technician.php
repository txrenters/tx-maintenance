<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-house technician roster (profile + the photo the tenant
 * appointment text attaches) and the technician chosen on a service
 * schedule. technician_id is a plain nullable column (no FK): a schedule
 * outlives roster edits, and a missing technician simply means no photo
 * goes out.
 *
 * Create-or-augment on purpose: a database that already carries the
 * larger technicians table from the parked scheduler branch only gains
 * the profile columns it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technicians')) {
            Schema::create('technicians', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('role', 20)->default('repair');
                $table->boolean('is_active')->default(true);
                $table->string('phone', 40)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('specialty', 120)->nullable();
                $table->text('notes')->nullable();
                $table->string('photo_path', 255)->nullable();
                $table->string('photo_content_type', 100)->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('technicians', function (Blueprint $table) {
                foreach ([
                    'phone' => fn () => $table->string('phone', 40)->nullable(),
                    'email' => fn () => $table->string('email', 190)->nullable(),
                    'specialty' => fn () => $table->string('specialty', 120)->nullable(),
                    'photo_path' => fn () => $table->string('photo_path', 255)->nullable(),
                    'photo_content_type' => fn () => $table->string('photo_content_type', 100)->nullable(),
                ] as $column => $add) {
                    if (! Schema::hasColumn('technicians', $column)) {
                        $add();
                    }
                }
            });
        }

        if (Schema::hasTable('service_schedules') && ! Schema::hasColumn('service_schedules', 'technician_id')) {
            Schema::table('service_schedules', function (Blueprint $table) {
                $table->unsignedBigInteger('technician_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_schedules') && Schema::hasColumn('service_schedules', 'technician_id')) {
            Schema::table('service_schedules', function (Blueprint $table) {
                $table->dropColumn('technician_id');
            });
        }

        // Only drop the table this migration itself created; the parked
        // scheduler branch's larger table is left alone on rollback.
        if (Schema::hasTable('technicians') && ! Schema::hasColumn('technicians', 'jobber_user_gid')) {
            Schema::drop('technicians');
        }
    }
};
