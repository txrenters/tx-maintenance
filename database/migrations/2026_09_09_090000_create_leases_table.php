<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PropertyWare leases, so the app can show a property's real lease status
 * (Active, Terminated, Notice Given, Eviction, Draft) instead of inferring
 * occupancy from the job type and whether an imported work order happened to
 * carry a lease (WorkOrder::scopeWithoutLease()).
 *
 * building_id holds the building's PropertyWare id, not its local primary key,
 * matching the existing WorkOrder::building() convention
 * (belongsTo(Building::class, 'building_id', 'propertyware_id')). It is indexed
 * rather than a real foreign key because leases can arrive from PropertyWare
 * before the building they reference has been imported.
 *
 * status is a plain string, not an enum: PropertyWare varies picklist casing
 * and spacing, and a value we have not seen before must not fail the sync.
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
            $table->bigInteger('propertyware_id')->unique();
            $table->bigInteger('building_id')->nullable()->index();
            $table->bigInteger('unit_id')->nullable();
            $table->string('status')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('tenant_name')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
