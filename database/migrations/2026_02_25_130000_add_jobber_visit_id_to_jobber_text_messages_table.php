<?php

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
        Schema::table('jobber_text_messages', function (Blueprint $table) {
            $table->foreignId('jobber_visit_id')
                ->nullable()
                ->after('jobber_id')
                ->constrained('jobber_visits')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_text_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jobber_visit_id');
        });
    }
};
