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
        Schema::table('tenant_email_notifications', function (Blueprint $table) {
            $table->string('direction')->default('outbound')->after('type')->index();
            $table->string('correlation_tag')->nullable()->after('to_email')->index();
            $table->string('in_reply_to')->nullable()->after('internet_message_id')->index();
            $table->boolean('has_attachments')->default(false)->after('in_reply_to');
            $table->foreignId('sent_by_user_id')->nullable()->after('has_attachments')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_email_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn(['direction', 'correlation_tag', 'in_reply_to', 'has_attachments']);
        });
    }
};
