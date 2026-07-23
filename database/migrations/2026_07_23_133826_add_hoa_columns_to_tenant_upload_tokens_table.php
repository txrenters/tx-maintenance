<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOA-violation columns on the tenant portal token. The token is the
 * per-violation unit of work (one per work order per purpose), so the
 * notice deadline and the idempotency stamps for the escalation flag and
 * the corrected-confirmation email live here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_upload_tokens', function (Blueprint $table) {
            // Date printed on the HOA notice; anchors the 5-business-day deadline.
            $table->date('hoa_notice_date')->nullable()->after('purpose');
            // Notice date + 5 business days: reminders stop and escalation fires here.
            $table->dateTime('hoa_deadline_at')->nullable()->after('hoa_notice_date');
            // Stamped once when staff are flagged to assign a vendor (deadline missed).
            $table->dateTime('escalation_flagged_at')->nullable()->after('hoa_deadline_at');
            // Stamped once when the tenant+owner corrected-confirmation email is sent.
            $table->dateTime('confirmation_sent_at')->nullable()->after('escalation_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_upload_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'hoa_notice_date',
                'hoa_deadline_at',
                'escalation_flagged_at',
                'confirmation_sent_at',
            ]);
        });
    }
};
