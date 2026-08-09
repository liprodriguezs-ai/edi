<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §5.4 r2 — partial unique index on user.phone (no OTP login; index only).
 */
final class UserPhoneUniqueTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    public function test_two_users_with_same_non_null_phone_conflict(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        User::create(['name' => 'Phone A', 'email' => 'pa@example.com', 'password' => null, 'phone' => '5551234567']);
        User::create(['name' => 'Phone B', 'email' => 'pb@example.com', 'password' => null, 'phone' => '5551234567']);
    }

    public function test_two_users_with_null_phone_coexist(): void
    {
        $before = User::withoutTenancy()->whereNull('phone')->count();

        User::create(['name' => 'No Phone A', 'email' => 'npa@example.com', 'password' => null, 'phone' => null]);
        User::create(['name' => 'No Phone B', 'email' => 'npb@example.com', 'password' => null, 'phone' => null]);

        $this->assertSame($before + 2, User::withoutTenancy()->whereNull('phone')->count());
    }

    public function test_soft_deleted_user_frees_its_phone_for_reuse(): void
    {
        $first = User::create(['name' => 'Reuse A', 'email' => 'ra@example.com', 'password' => null, 'phone' => '5559998888']);
        $first->delete();

        // The partial index excludes soft-deleted rows, so the phone can be reused.
        $second = User::create(['name' => 'Reuse B', 'email' => 'rb@example.com', 'password' => null, 'phone' => '5559998888']);
        $this->assertNotNull($second->id);
    }
}
