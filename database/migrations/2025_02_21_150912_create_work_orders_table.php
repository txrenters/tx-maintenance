<?php

use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('client_data')->nullable();
            $table->bigInteger('propertyware_id')->nullable();
            $table->bigInteger('work_order_no')->nullable();
            $table->string('approval_comments')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->string('approved_by')->nullable();
            $table->date('approved_date')->nullable();
            $table->string('authorized_to_enter')->nullable();
            $table->string('category')->nullable();
            $table->string('closing_comments')->nullable();
            $table->date('completed_date')->nullable();
            $table->decimal('cost_estimate', 10, 2)->nullable();
            $table->timestamp('created_date')->nullable();
            $table->date('date_to_enter')->nullable();
            $table->text('description')->nullable();
            $table->integer('hour_estimate')->nullable();
            $table->string('location')->nullable();
            $table->string('priority')->nullable();
            $table->integer('priority_as_int')->default(0);
            $table->string('required_materials')->nullable();
            $table->date('scheduled_end_date')->nullable();
            $table->string('service_request_building')->nullable();
            $table->string('service_request_company_name')->nullable();
            $table->string('service_request_contact_email')->nullable();
            $table->string('service_request_contact_name')->nullable();
            $table->string('service_request_contact_phone')->nullable();
            $table->string('service_request_contact_phone_type')->nullable();
            $table->string('service_request_unit')->nullable();
            $table->string('source')->nullable();
            $table->string('specific_location')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->decimal('total_hour_work', 10, 2)->nullable();
            $table->string('type')->nullable();

            $table->bigInteger('building_id')->nullable();
            $table->bigInteger('lease_id')->nullable();
            $table->bigInteger('portfolio_id')->nullable();

            $table->text('remarks')->nullable();
            $table->text('notes')->nullable();
            $table->longText('unit_id')->nullable();

            $table->string('zone')->nullable();
            $table->string('additional_work_needed_reschedule')->nullable();
            $table->string('management_plan')->nullable();
            $table->timestamp('end_date')->nullable();

            $table->string('local_status')->default('Created');
            $table->boolean('is_emergency')->default(false);
            $table->boolean('is_single_vendor')->default(true);
            $table->foreignIdFor(ServiceStatus::class, 'service_status_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Owner::class, 'owner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tenants::class, 'tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
