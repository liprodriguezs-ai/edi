<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitation', function (Blueprint $table) {
            // Plaintext token is no longer persisted for new invitations (it is emailed only);
            // kept nullable for backward compatibility with legacy rows created before hashing.
            $table->string('token', 64)->nullable()->change();

            $table->string('token_hash', 64)->nullable()->after('token')
                ->comment('SHA-256 hex hash of the invitation token (at-rest). The plaintext is emailed, never stored.');
            $table->uuid('unit_relationship_id')->nullable()->after('inviteable_id')
                ->comment('FK (bare uuid) to unit_relationship: relationship the invitee will hold (family_resident/renter/co_owner/billing_contact). Null for non-resident invitations.');
            $table->uuid('invited_by_user_id')->nullable()->after('email')
                ->comment('FK (bare uuid) to user that issued the invitation (inviting owner/admin). Null for system-generated invitations.');
            $table->timestamp('accepted_at')->nullable()->after('expires_at')
                ->comment('Datetime the invitation was redeemed; non-null = consumed (no longer valid).');
            $table->timestamp('revoked_at')->nullable()->after('accepted_at')
                ->comment('Datetime the invitation was revoked by an owner/admin; non-null = invalid.');

            // GLOBAL unique (no tenant_id): verify/accept run without tenant context — this is the public lookup key.
            $table->unique('token_hash');
            $table->index(['tenant_id', 'inviteable_type', 'inviteable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_invitation', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropIndex(['tenant_id', 'inviteable_type', 'inviteable_id']);
            $table->dropColumn([
                'token_hash',
                'unit_relationship_id',
                'invited_by_user_id',
                'accepted_at',
                'revoked_at',
            ]);
            $table->string('token', 64)->nullable(false)->change();
        });
    }
};
