<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes to add, keyed by table. work_orders shipped with no explicit
     * indexes at all, so every board and dashboard query full-scanned it.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private array $indexes = [
        'work_orders' => [
            ['status', 'service_status_id'],
            ['work_order_no'],
            ['propertyware_id'],
            ['created_date'],
            ['completed_date'],
        ],
        'work_order_vendors' => [
            ['vendor_id', 'work_order_id'],
        ],
    ];

    /**
     * Guarded per index so the migration is idempotent: production runs it
     * manually over SSH and startup.sh may run it again on the next container
     * restart — an unguarded duplicate ADD INDEX would fail that migrate and
     * leave the site in maintenance mode. hasIndex() by column list also
     * skips any manually created index covering the same columns.
     */
    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($tableName, $columns)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns) {
                    $table->index($columns);
                });
            }
        }
    }

    /**
     * Drops only indexes carrying the default Laravel name this migration
     * would have created — never a pre-existing index that merely covers the
     * same columns (which is what made up() skip creating one).
     */
    public function down(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            foreach ($indexes as $columns) {
                $indexName = $tableName.'_'.implode('_', $columns).'_index';

                if (! Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }
    }
};
