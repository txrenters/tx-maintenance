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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('client_data')->nullable();
            $table->string('propertyware_id')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('email')->nullable();
            $table->string('fax')->nullable();
            $table->string('home_phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('work_phone')->nullable();
            $table->string('pager')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('web_address')->nullable();
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            $table->string('website')->nullable();
            $table->string('ssn')->nullable();
            $table->string('search_tag')->nullable();
            $table->string('salutation')->nullable();
            $table->string('name_on_check')->nullable();
            $table->boolean('is_name_on_lease')->default(true);
            $table->boolean('is_dirty')->default(false);
            $table->text('comments')->nullable();
            $table->longText('documents')->nullable();
            $table->longText('notes')->nullable();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
