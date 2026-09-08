<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lease status per property, imported from PropertyWare's saved-report export
 * by `sync:leases`, so the app can show a real status (Active, Terminated,
 * Notice Given, Eviction) instead of inferring occupancy from the job type and
 * whether an imported work order happened to carry a lease
 * (WorkOrder::scopeWithoutLease()).
 *
 * building_id is the key rather than a PropertyWare lease id: the report feed
 * does not expose one, and it carries the current lease per property, which is
 * what the column shows. It holds the building's PropertyWare id, not the local
 * primary key, matching the existing WorkOrder::building() convention. Unique
 * so a building cannot end up with two competing statuses; indexed by virtue of
 * being unique. Not a foreign key because it is written by a sync that matches
 * on address, and a building can be renamed or re-imported underneath it.
 *
 * status is a plain string, not an enum: PropertyWare varies picklist casing
 * and a value we have not seen must not fail the sync.
 *
 * Guarded so a startup.sh double-run or a re-run after a partial deploy
 * converges instead of erroring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leases')) {
            return;
        }

        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('building_id')->unique();
            $table->string('status')->nullable();
            $table->string('tenant_name')->nullable();
            // The report address this row matched, so an unexpected pairing can
            // be traced back without re-running the whole sync.
            $table->string('address')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
