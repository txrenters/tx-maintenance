<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when the vendor photo-request text went live, so the sweep only
 * texts vendors about checklist lines that appeared AFTER this moment.
 * Earl (2026-10-14): "regarding with text, it should not be backfilled" -
 * the open backlog of "If Pictures Have not been uploaded contact vendor
 * to upload" lines stays with the coordinator.
 *
 * Deploy: one app_settings row, written once; a re-run keeps the original
 * time. Rollback removes the row (which would let a later re-migrate text
 * about older lines, so only roll back together with the feature).
 */
return new class extends Migration
{
    public const KEY = 'vendor_photo_request.started_at';

    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        if (DB::table('app_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        $now = now();

        DB::table('app_settings')->insert([
            'key' => self::KEY,
            'value' => json_encode(['started_at' => $now->toIso8601String()]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->where('key', self::KEY)->delete();
    }
};
