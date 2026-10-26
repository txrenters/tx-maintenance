<?php

use App\Services\AnsweredNotificationReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * One-time cleanup: messages replied to before replies stamped answered_at
     * stayed under Awaiting reply whenever the reply was filed under another
     * owner or vendor. Stamp every waiting message that has a reply after it.
     */
    public function up(): void
    {
        $stamped = app(AnsweredNotificationReader::class)->backfillAnswered();

        Log::info('Backfilled answered_at on replied messages.', ['stamped' => $stamped]);
    }

    /**
     * Not reversible: the stamps cannot be told apart from live ones.
     */
    public function down(): void {}
};
