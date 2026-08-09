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
        Schema::table('user', function (Blueprint $table) {
            if (! Schema::hasColumn('user', 'deleted_request_user_id')) {
                $table->foreignUuid('deleted_request_user_id')->nullable()->references('id')->on('user')->comment('FK to user who requested the account deletion.');
            }
            if (! Schema::hasColumn('user', 'deleted_reason')) {
                $table->string('deleted_reason')->nullable()->comment('Reason provided when requesting account deletion.');
            }
            if (! Schema::hasColumn('user', 'deleted_confirm_user_id')) {
                $table->foreignUuid('deleted_confirm_user_id')->nullable()->references('id')->on('user')->comment('FK to user (admin) who confirmed the deletion request.');
            }
        });

        /* Schema::table('order', function (Blueprint $table) {
            if (Schema::hasColumn('order', 'deleted_request')) {
                $table->dropColumn('deleted_request');
            }
            if (!Schema::hasColumn('order', 'deleted_request_user_id')) {
                $table->foreignUuid('deleted_request_user_id')->nullable()->references('id')->on('user');
            }
            if (!Schema::hasColumn('order', 'deleted_confirm_user_id')) {
                $table->foreignUuid('deleted_confirm_user_id')->nullable()->references('id')->on('user');
            }
        });*/
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['deleted_request_user_id']);
            $table->dropForeign(['deleted_confirm_user_id']);
            $table->dropColumn('deleted_request_user_id');
            $table->dropColumn('deleted_reason');
            $table->dropColumn('deleted_confirm_user_id');
        });

        /* Schema::table('order', function (Blueprint $table) {
            $table->dropForeign(['deleted_request_user_id']);
            $table->dropForeign(['deleted_confirm_user_id']);
            $table->dropColumn('deleted_request_user_id');
            $table->dropColumn('deleted_confirm_user_id');

            $table->boolean('deleted_request')->default(false);
        });*/
    }
};
