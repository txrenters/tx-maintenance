<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $supportsAddingForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        if ($supportsAddingForeignKeys) {
            Schema::table('owner_email_notifications', function (Blueprint $table) {
                $table->dropForeign(['work_order_id']);
            });
        }
        Schema::table('owner_email_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_id')->nullable()->change();
        });
        if ($supportsAddingForeignKeys) {
            Schema::table('owner_email_notifications', function (Blueprint $table) {
                $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            });
        }

        if ($supportsAddingForeignKeys) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropForeign(['work_order_id']);
            });
        }
        Schema::table('email_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_id')->nullable()->change();
        });
        if ($supportsAddingForeignKeys) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $supportsAddingForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        if ($supportsAddingForeignKeys) {
            Schema::table('owner_email_notifications', function (Blueprint $table) {
                $table->dropForeign(['work_order_id']);
            });
        }
        Schema::table('owner_email_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_id')->nullable(false)->change();
        });
        if ($supportsAddingForeignKeys) {
            Schema::table('owner_email_notifications', function (Blueprint $table) {
                $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            });
        }

        if ($supportsAddingForeignKeys) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropForeign(['work_order_id']);
            });
        }
        Schema::table('email_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_id')->nullable(false)->change();
        });
        if ($supportsAddingForeignKeys) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            });
        }
    }
};
