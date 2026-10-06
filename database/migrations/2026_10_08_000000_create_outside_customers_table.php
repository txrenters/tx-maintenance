<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who are not Texas Renters properties: the people who call
 * Crystal Creek Air (our own repair brand, worked by the THMP crew) for a
 * repair at their own home. They have no PropertyWare record, so their
 * name, address and contact details live here, typed in by the office.
 *
 * jobber_client_gid / jobber_property_gid remember where the customer sits
 * in Jobber (a property under the "Crystal Creek Air, LLC" client), so a
 * repeat caller is not created in Jobber twice.
 *
 * work_orders.outside_customer_id is a plain nullable column (no FK), like
 * building_id: a work order outlives any later cleanup of the customer list.
 *
 * Guarded both ways — a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outside_customers')) {
            Schema::create('outside_customers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 190);
                $table->string('phone', 40)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('street', 190)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 40)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('jobber_client_gid', 120)->nullable();
                $table->string('jobber_property_gid', 120)->nullable();
                $table->timestamps();

                $table->index('phone');
                $table->index('email');
                $table->index(['street', 'postal_code']);
            });
        }

        if (Schema::hasTable('work_orders') && ! Schema::hasColumn('work_orders', 'outside_customer_id')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('outside_customer_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('work_orders') && Schema::hasColumn('work_orders', 'outside_customer_id')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropColumn('outside_customer_id');
            });
        }

        Schema::dropIfExists('outside_customers');
    }
};
