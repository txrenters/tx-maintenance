<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->text('maintenance_notice')->nullable()->after('country');
            $table->decimal('maintenance_spending_limit_amount', 12, 2)->nullable()->after('maintenance_notice');
            $table->string('maintenance_spending_limit_time')->nullable()->after('maintenance_spending_limit_amount');
            $table->decimal('maintenance_labor_surcharge_amount', 12, 2)->nullable()->after('maintenance_spending_limit_time');
            $table->string('maintenance_labor_surcharge_type')->nullable()->after('maintenance_labor_surcharge_amount');
            $table->string('category')->nullable()->after('maintenance_labor_surcharge_type');
            $table->string('property_type')->nullable()->after('category');
            $table->json('custom_fields')->nullable()->after('property_type');
            $table->timestamp('details_synced_at')->nullable()->after('custom_fields');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_notice',
                'maintenance_spending_limit_amount',
                'maintenance_spending_limit_time',
                'maintenance_labor_surcharge_amount',
                'maintenance_labor_surcharge_type',
                'category',
                'property_type',
                'custom_fields',
                'details_synced_at',
            ]);
        });
    }
};
