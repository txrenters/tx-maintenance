<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\WorkOrderNotePushService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends dashboard notes that PropertyWare has not accepted yet.
 *
 * A note typed on the Notes tab is pushed to PropertyWare once, as it is
 * saved. When that push fails — PropertyWare unreachable, an error page, a
 * fault — the note keeps living on the dashboard with no PropertyWare id and
 * nothing used to try again. This runs a few minutes after every fast-lane
 * import, reads each such work order's notes back from PropertyWare first
 * (a note that did arrive is linked, never doubled) and re-sends the rest,
 * backing off as the note ages (see WorkOrderNotePushService::isDue).
 */
class PushPendingWorkOrderNotesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notes:push-pending
        {--dry-run : List the notes that would be sent and call PropertyWare for nothing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send dashboard work order notes PropertyWare has not accepted yet. Scheduled every 10 minutes; safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(WorkOrderNotePushService $pusher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $due = WorkOrderNotes::query()
            ->whereNotNull('user_id')
            ->whereNull('propertyware_id')
            ->where('created_at', '<=', $now->copy()->subMinutes(WorkOrderNotePushService::GRACE_MINUTES))
            ->where('created_at', '>=', $now->copy()->subDays(WorkOrderNotePushService::GIVE_UP_DAYS))
            ->orderBy('id')
            ->get()
            ->filter(fn (WorkOrderNotes $note) => $pusher->isDue($note, $now));

        if ($due->isEmpty()) {
            $this->info('No work order notes are waiting for PropertyWare.');

            return self::SUCCESS;
        }

        // Console runs have no signed-in user, so the scope is a no-op; spelled
        // out anyway, as in every other PropertyWare path that touches notes.
        $workOrders = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->whereIn('id', $due->pluck('work_order_id')->unique()->all())
            ->get(['id', 'work_order_no', 'propertyware_id'])
            ->keyBy('id');

        if ($dryRun) {
            $this->table(
                ['Note', 'Work order', 'Subject', 'Written', 'Last attempt'],
                $due->map(fn (WorkOrderNotes $note) => [
                    $note->id,
                    $workOrders->get($note->work_order_id)?->work_order_no ?? "(id {$note->work_order_id})",
                    Str::limit((string) $note->subject, 40),
                    $note->created_at?->diffForHumans($now),
                    $note->updated_at?->diffForHumans($now),
                ])->all(),
            );
            $this->info("Dry run: {$due->count()} note(s) would be sent; PropertyWare was not called.");

            return self::SUCCESS;
        }

        $counts = ['sent' => 0, 'linked' => 0, 'refused' => 0, 'unreadable' => 0, 'waiting' => 0];

        /** @var Collection<int, WorkOrderNotes> $notes */
        foreach ($due->groupBy('work_order_id') as $workOrderId => $notes) {
            $workOrder = $workOrders->get($workOrderId);

            if ($workOrder === null || blank($workOrder->propertyware_id)) {
                // Not in PropertyWare itself yet; the import will give it an id.
                $notes->each->touchQuietly();
                $counts['waiting'] += $notes->count();

                continue;
            }

            if (! $pusher->reconcile($workOrder)) {
                $notes->each->touchQuietly();
                $counts['unreadable'] += $notes->count();
                $this->warn("WO #{$workOrder->work_order_no}: PropertyWare could not be read, {$notes->count()} note(s) left for a later run.");

                continue;
            }

            foreach ($notes as $note) {
                $note->refresh();

                if (! blank($note->propertyware_id)) {
                    $counts['linked']++;
                    $this->line("WO #{$workOrder->work_order_no}: note {$note->id} was already in PropertyWare, linked.");

                    continue;
                }

                if ($pusher->push($note)) {
                    $counts['sent']++;
                    $this->line("WO #{$workOrder->work_order_no}: note {$note->id} sent.");
                } else {
                    $counts['refused']++;
                    $this->warn("WO #{$workOrder->work_order_no}: note {$note->id} refused again; see the log.");
                }
            }
        }

        $summary = sprintf(
            'Pending notes: %d sent, %d already in PropertyWare, %d refused, %d unreadable, %d waiting for a PropertyWare work order.',
            $counts['sent'],
            $counts['linked'],
            $counts['refused'],
            $counts['unreadable'],
            $counts['waiting'],
        );

        $this->info($summary);
        Log::info('Pending work order notes run.', $counts);

        return self::SUCCESS;
    }
}
