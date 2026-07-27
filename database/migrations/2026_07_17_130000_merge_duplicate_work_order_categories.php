<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The categories table holds near-duplicate rows that differ only in case
     * or invisible whitespace — e.g. "HVAC" and "HVAC " (trailing space).
     * PropertyWare's picklist knows exactly one spelling, so picking the wrong
     * twin in the edit modal makes the PropertyWare sync fail, and the board's
     * category filter splits one category into two.
     *
     * For each duplicate group this keeps the variant actually used by the
     * most work orders (the synced-from-PropertyWare evidence), rewrites every
     * work order to that canonical spelling, and deletes the twins.
     */
    public function up(): void
    {
        // MySQL compares strings trim/case-insensitively under the default
        // collation, which would lump both twins together; BINARY forces an
        // exact match. SQLite (local/tests) is already exact with plain `=`.
        $binaryEq = DB::connection()->getDriverName() === 'mysql'
            ? 'BINARY category = ?'
            : 'category = ?';

        $categories = DB::table('work_order_categories')->get(['id', 'name']);

        $groups = $categories->groupBy(fn (object $row): string => mb_strtolower(trim((string) $row->name)));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $usage = $group->mapWithKeys(function (object $row) use ($binaryEq): array {
                return [$row->id => DB::table('work_orders')->whereRaw($binaryEq, [$row->name])->count()];
            });

            $canonical = $group
                ->sortBy([
                    fn (object $a, object $b): int => $usage[$b->id] <=> $usage[$a->id],
                    fn (object $a, object $b): int => $a->id <=> $b->id,
                ])
                ->first();

            foreach ($group as $row) {
                if ($row->id === $canonical->id) {
                    continue;
                }

                DB::table('work_orders')
                    ->whereRaw($binaryEq, [$row->name])
                    ->update(['category' => $canonical->name]);

                DB::table('work_order_categories')->where('id', $row->id)->delete();
            }
        }
    }

    /**
     * Data cleanup; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
