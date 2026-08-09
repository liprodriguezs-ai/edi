<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogos cuyo campo `order` se vuelve nullable.
     *
     * El campo se ocultó de la UI (forms/listas) y dejó de ser obligatorio. Se
     * MANTIENE el `default(0)` para no romper el ordenamiento existente
     * (orderBy('order') en DataImport y el sort por defecto del frontend): los
     * registros sin valor explícito siguen cayendo en 0.
     */
    private array $tables = [
        // Access
        'visitor_motive', 'visitor_status', 'qr_type', 'access_entry_type',
        'access_verification', 'blacklist_type',
        // Announcements
        'announcement_type', 'announcement_priority', 'announcement_status',
        // Board
        'board_role', 'board_period_status',
        // Chat
        'chat_channel_type',
        // Collaborator
        'employee_position', 'pay_frequency', 'employee_shift',
        // Collections
        'charge_status', 'charge_batch_status', 'payment_status',
        'charge_concept_category', 'amount_mode', 'periodicity', 'payment_method',
        // Housing
        'section', 'tower', 'unit_type', 'unit_status', 'unit_relationship',
        // Projects
        'community_project_status', 'community_project_category',
        'participation_type', 'project_contribution_status',
        // Providers
        'provider_type', 'provider_service_type',
        // Treasury
        'bank_account_type', 'bank_account_purpose', 'expense_category',
        'expense_status', 'beneficiary_type',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('order')->default(0)->nullable()
                    ->comment('Display sort order (nullable; oculto de la UI).')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            // Normaliza los NULL a 0 antes de volver la columna NOT NULL.
            DB::table($table)->whereNull('order')->update(['order' => 0]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('order')->default(0)
                    ->comment('Display sort order')
                    ->change();
            });
        }
    }
};
