<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who the visit is assigned to in Jobber, stored as [{id, name}, ...]
     * straight from the sync. Guarded so a re-run after a partial deploy or
     * a startup.sh double-run converges instead of erroring.
     */
    public function up(): void
    {
        if (Schema::hasColumn('jobber_visits', 'assigned_to')) {
            return;
        }

        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->json('assigned_to')->nullable()->after('visit_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('jobber_visits', 'assigned_to')) {
            return;
        }

        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->dropColumn('assigned_to');
        });
    }
};
