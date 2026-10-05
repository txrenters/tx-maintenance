<?php

namespace App\Console\Commands;

use App\Models\Owner;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

/**
 * Fills in owners.phone for owners on a work order whose PropertyWare owner
 * record has no phone, from the number PropertyWare keeps on their contact
 * (WO #43819: blank owner record, contact home phone set). New syncs do this
 * on their own (see OwnerPhoneResolver); this catches up the rows stored
 * before that.
 *
 * Contacts nobody: it writes the column with the query builder (no model
 * events), dispatches no job and sends no text or email. Owners get texts
 * only from later new events on their work orders. It refuses to run while
 * the daily owner appointment follow-up text is on, because that scan would
 * pick up the newly filled numbers for appointments already set.
 */
class BackfillOwnerPhones extends Command
{
    public const LOG_DESCRIPTION = 'owner phone backfilled from the PropertyWare contact';

    public const UNDO_DESCRIPTION = 'owner phone backfill undone';

    protected $signature = 'owners:backfill-phones
        {--dry-run : Look every owner up and show what would be filled in, changing nothing}
        {--limit= : Stop after this many owners}
        {--sleep=250 : Milliseconds to rest between PropertyWare calls}
        {--force : Apply even though the owner appointment follow-up text is on}
        {--undo : Clear every phone this command filled in that nobody has changed since}';

    protected $description = 'Fill in blank owner phones on work orders from the PropertyWare contact record, texting nobody.';

    public function handle(PropertyWareService $propertyWare): int
    {
        if ($this->option('undo')) {
            return $this->undo();
        }

        $dryRun = (bool) $this->option('dry-run');
        $followUpOn = (bool) config('services.twilio.owner_schedule_followup_sms');

        $this->line('Owner appointment follow-up text (OWNER_SCHEDULE_FOLLOWUP_SMS_ENABLED): '.($followUpOn ? 'ON' : 'off').'.');

        if ($followUpOn && ! $dryRun && ! $this->option('force')) {
            $this->error('Not applied: the daily owner follow-up text is on and would text the owners filled in here. Turn it off first, or pass --force.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = DB::table('owners')
            ->whereIn('id', DB::table('work_order_owners')->select('owner_id'))
            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->where(fn ($q) => $q->whereNotNull('contact_id')->orWhereNotNull('propertyware_id'))
            ->orderBy('id');

        $total = $query->count();
        $planned = $limit !== null ? min($limit, $total) : $total;

        $this->info(sprintf(
            '%d owner(s) on a work order have no phone; %d will be looked up%s.',
            $total,
            $planned,
            $dryRun ? ' (dry run, nothing will be saved)' : ''
        ));

        $filled = 0;
        $none = 0;
        $done = 0;

        foreach ($query->lazyById(100) as $owner) {
            if ($limit !== null && $done >= $limit) {
                break;
            }

            if ($done > 0) {
                Sleep::usleep(max(0, (int) $this->option('sleep')) * 1000);
            }

            $done++;
            $label = sprintf('#%d  %s', $owner->id, $owner->name ?? trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')));
            $phone = $propertyWare->getContactPhone($owner->contact_id ?? $owner->propertyware_id);

            if ($phone === null) {
                $none++;
                $this->line("{$label}  no phone on the contact");

                continue;
            }

            $filled++;

            if ($dryRun) {
                $this->line("{$label}  would set {$phone}");

                continue;
            }

            // Only a still-blank row: a sync may have filled it meanwhile.
            $updated = DB::table('owners')
                ->where('id', $owner->id)
                ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
                ->update(['phone' => $phone]);

            if ($updated === 0) {
                $filled--;
                $this->line("{$label}  left alone: filled in meanwhile");

                continue;
            }

            activity('owner')
                ->performedOn((new Owner)->forceFill(['id' => $owner->id]))
                ->withProperties(['phone' => $phone, 'contact_id' => $owner->contact_id])
                ->log(self::LOG_DESCRIPTION);

            $this->line("{$label}  set {$phone}");
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Would fill in' : 'Filled in', $filled],
            ['No phone on the contact (or lookup failed)', $none],
        ]);

        if ($dryRun && $filled > 0) {
            $this->info('Dry run: nothing was saved. Run again without --dry-run to fill these in.');
        }

        return self::SUCCESS;
    }

    /**
     * Put back the blanks this command filled in, but only where the phone is
     * still the one it wrote: anything changed since is kept.
     */
    private function undo(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $entries = Activity::query()
            ->where('description', self::LOG_DESCRIPTION)
            ->where('subject_type', (new Owner)->getMorphClass())
            ->orderBy('id')
            ->get();

        $this->info(sprintf('%d backfill entr%s found%s.', $entries->count(), $entries->count() === 1 ? 'y' : 'ies', $dryRun ? ' (dry run, nothing will be changed)' : ''));

        $cleared = 0;
        $kept = 0;

        foreach ($entries as $entry) {
            $written = (string) data_get($entry->properties, 'phone');
            $current = DB::table('owners')->where('id', $entry->subject_id)->value('phone');

            if (blank($current)) {
                continue;
            }

            if ((string) $current !== $written) {
                $kept++;
                $this->line("#{$entry->subject_id}  kept: now {$current}, changed since the backfill wrote {$written}");

                continue;
            }

            $cleared++;

            if ($dryRun) {
                $this->line("#{$entry->subject_id}  would clear {$written}");

                continue;
            }

            DB::table('owners')->where('id', $entry->subject_id)->update(['phone' => null]);

            activity('owner')
                ->performedOn((new Owner)->forceFill(['id' => $entry->subject_id]))
                ->withProperties(['phone' => $written])
                ->log(self::UNDO_DESCRIPTION);

            $this->line("#{$entry->subject_id}  cleared {$written}");
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Would clear' : 'Cleared', $cleared],
            ['Kept (changed since)', $kept],
        ]);

        return self::SUCCESS;
    }
}
