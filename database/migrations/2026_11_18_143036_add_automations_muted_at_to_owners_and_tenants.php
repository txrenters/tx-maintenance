<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner phone numbers muted when this column ships, matched on their last
     * ten digits. Thomas Sutherland's property is handled by someone else, who
     * asked that he not receive automated maintenance messages.
     *
     * @var list<string>
     */
    private const INITIALLY_MUTED_OWNER_PHONES = ['7135698343'];

    /**
     * A per-person "do not send automated messages" switch, managed from the
     * Do Not Message tab on IT Tools → Automated Messages. Manual staff sends
     * never consult it.
     */
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->timestamp('automations_muted_at')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('automations_muted_at')->nullable();
        });

        $lastTenDigits = fn (?string $number): string => substr(preg_replace('/\D+/', '', (string) $number) ?? '', -10);

        DB::table('owners')
            ->where(function ($query) {
                foreach (self::INITIALLY_MUTED_OWNER_PHONES as $number) {
                    $suffix = substr($number, -4);
                    $query->orWhere('phone', 'like', "%{$suffix}")->orWhere('mobile', 'like', "%{$suffix}");
                }
            })
            ->get(['id', 'phone', 'mobile'])
            ->filter(fn (object $owner): bool => in_array($lastTenDigits($owner->phone), self::INITIALLY_MUTED_OWNER_PHONES, true)
                || in_array($lastTenDigits($owner->mobile), self::INITIALLY_MUTED_OWNER_PHONES, true))
            ->each(fn (object $owner) => DB::table('owners')->where('id', $owner->id)->update(['automations_muted_at' => now()]));
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn('automations_muted_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('automations_muted_at');
        });
    }
};
