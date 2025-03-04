<?php

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
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('client_data')->nullable();
            $table->bigInteger('propertyware_id')->nullable();
            $table->bigInteger('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->string('name_on_check')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('fax')->nullable();
            $table->string('pager')->nullable();
            $table->string('mobile')->nullable();
            $table->string('phone')->nullable();
            $table->string('home_phone')->nullable();
            $table->string('work_phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('work_telephone')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('company')->nullable();
            $table->string('tax_id')->nullable();
            $table->longText('notes')->nullable();
            $table->bigInteger('percentage_ownership')->nullable();
            $table->boolean('is_property_restricted')->default(false);
            $table->bigInteger('org_id')->nullable();
            $table->string('status')->nullable();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
