<?php

use App\Models\Conversation;
use App\Services\TenantPhotoMirrorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * One-shot backfill: photos tenants already sent through their
     * conversation thread (MMS replies and portal chat messages) on OPEN work
     * orders are mirrored into the Attachments tab, so the fix also covers the
     * work orders staff reported (e.g. WO #43630), not only new messages.
     *
     * Deliberately conservative: no PropertyWare sync (a deploy must not
     * burst-upload history) and every mirror is stamped as already viewed
     * (staff have had these photos in the thread all along — no badge blast).
     * Best-effort per photo; a failure is logged, never fatal to the boot
     * migration run.
     */
    public function up(): void
    {
        try {
            $openWorkOrderIds = DB::table('work_orders')->where('status', 'Open')->select('id');

            $conversations = Conversation::query()
                ->withoutGlobalScopes()
                ->with('media')
                ->where('conversation_type', 'tenant')
                ->where('is_read', false)
                ->whereHas('media')
                ->whereIn('work_order_id', $openWorkOrderIds)
                ->get();

            $mirror = app(TenantPhotoMirrorService::class);
            $mirrored = 0;

            foreach ($conversations as $conversation) {
                $mirrored += count($mirror->mirrorForConversation(
                    $conversation,
                    $conversation->media,
                    syncToPropertyWare: false,
                    markViewed: true
                ));
            }

            Log::info('Backfilled tenant conversation photos into attachments', [
                'conversations' => $conversations->count(),
                'attachments_created' => $mirrored,
            ]);
        } catch (Throwable $e) {
            Log::error('Tenant conversation photo backfill failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Data backfill — nothing sensible to reverse.
     */
    public function down(): void
    {
        //
    }
};
