<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the six triggers Service THMP asked for (2026-10-08) onto the template
 * lines that already exist, so the automation starts without anyone editing
 * a template.
 *
 * Lines are matched on a normalized name (lower case, quotes dropped, runs of
 * whitespace collapsed) because production titles were edited by hand and
 * the seeded "Contact Tenant ... between  2 and 3 days" carries two spaces.
 * Only rows with no trigger yet are touched, so a re-run changes nothing and
 * a pick made on the Task Templates page is never overwritten. A title this
 * misses is one dropdown pick on that page.
 *
 * Deploy: UPDATE on a handful of `tasks` rows, nothing else. Rollback nulls
 * the six keys back out.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>> trigger => normalized needles, any of which matches
     */
    private const MATCHES = [
        'tenant_contacted' => ['contact tenant to schedule appointment'],
        'schedule_start_set' => ['fill in scheduled date', 'fill in scheduled start date'],
        'schedule_end_set' => ['fill in projected service end date', 'fill in projected end date'],
        'repair_completed' => ['have you completed the repair'],
        'before_photo_added' => ['upload before pictures of problem', 'upload before pictures of the problem'],
        'after_photo_added' => ['after completing service - take after photos in app', 'after completing service - take after photos'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'auto_complete_trigger')) {
            return;
        }

        $seeded = 0;

        foreach (DB::table('tasks')->whereNull('auto_complete_trigger')->get(['id', 'name']) as $row) {
            $trigger = $this->triggerFor((string) $row->name);

            if ($trigger === null) {
                continue;
            }

            DB::table('tasks')->where('id', $row->id)->update(['auto_complete_trigger' => $trigger]);
            $seeded++;
        }

        Log::info('Seeded task auto-complete triggers.', ['rows' => $seeded]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tasks', 'auto_complete_trigger')) {
            return;
        }

        DB::table('tasks')
            ->whereIn('auto_complete_trigger', array_keys(self::MATCHES))
            ->update(['auto_complete_trigger' => null]);
    }

    private function triggerFor(string $name): ?string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', str_replace(['"', '“', '”'], '', mb_strtolower($name))));

        foreach (self::MATCHES as $trigger => $needles) {
            foreach ($needles as $needle) {
                if (str_starts_with($normalized, $needle)) {
                    return $trigger;
                }
            }
        }

        return null;
    }
};
