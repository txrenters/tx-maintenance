<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the four new triggers (Earl, 2026-10-14) onto the coordinator's
 * "Service Completed - Call Tenant for Followup" lines that already exist,
 * so the automation starts without anyone editing a template.
 *
 * Same shape as the 2026-10-09 seed: lines are matched on a normalized name
 * (lower case, quotes dropped, whitespace collapsed), only rows with no
 * trigger yet are touched, so a re-run changes nothing and a pick made on
 * the Task Templates page is never overwritten. A title this misses is one
 * dropdown pick on that page.
 *
 * Deploy: UPDATE on a handful of `tasks` rows, nothing else. Rollback nulls
 * the four keys back out.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>> trigger => normalized needles, any of which matches
     */
    private const MATCHES = [
        'cost_confirmed' => ['confirm cost of service with vendor'],
        'photos_on_file_or_vendor_asked' => ['if pictures have not been uploaded contact vendor'],
        'tenant_confirmed_completion' => ['contact tenant to confirm completion'],
        'checklist_otherwise_complete' => ['have all items in checklist been completed'],
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

        Log::info('Seeded coordinator task auto-complete triggers.', ['rows' => $seeded]);
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
