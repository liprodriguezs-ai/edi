<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Notification\Models\Notification;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $notification = Notification::find(Notification::REFER_WELCOME);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
