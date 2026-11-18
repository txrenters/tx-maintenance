<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PropertyWare-synced documents are streamed from PropertyWare on demand, so a
 * rotation cannot be baked into a file we never keep. The turn (0/90/180/270,
 * clockwise) is remembered here and applied every time the document is served.
 *
 * Guarded both ways so a re-run after a partial failure converges. Online DDL
 * on InnoDB (ADD COLUMN with a default is INSTANT on MariaDB 10.3+), and the
 * rollback touches no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_order_documents', 'rotation')) {
            return;
        }

        Schema::table('work_order_documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('rotation')->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_order_documents', 'rotation')) {
            return;
        }

        Schema::table('work_order_documents', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};
