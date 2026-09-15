<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor's own invoice number, as printed on the document they sent.
 *
 * Operation Accounting enters every invoice into the bill-pay platform under
 * that number and relies on it to catch the same invoice arriving twice. Until
 * now it lived only inside the uploaded PDF or photo, so they opened each file
 * to read it. Optional everywhere: a vendor who does not number invoices can
 * still upload, and the office fills or corrects it from the Invoices list.
 *
 * No index: the list searches it with LIKE '%...%', which an index cannot
 * serve, and a plain column keeps the rollback a single dropColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'invoice_number')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('invoice_number', 100)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'invoice_number')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('invoice_number');
            });
        }
    }
};
