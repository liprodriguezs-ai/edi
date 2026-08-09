<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /dev/login environment gate + throttle (sec-authn-03).
 *
 * Outside local/testing the dev routes must be invisible (404): in production
 * they would expose an unthrottled password oracle against the web guard.
 */
final class DevLoginGateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The web group runs EncryptCookies, which requires an app key; the
        // test environment does not define one (API tests never need it).
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_dev_login_routes_return_404_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->get('/dev/login')->assertNotFound();
        $this->post('/dev/login', ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
    }

    public function test_dev_login_screen_renders_in_testing_environment(): void
    {
        $this->get('/dev/login')->assertOk();
    }

    public function test_dev_login_post_is_rate_limited(): void
    {
        $this->withoutMiddleware(ValidateCsrfTokens::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/dev/login', [
                'email' => 'dev@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(302);
        }

        $this->post('/dev/login', [
            'email' => 'dev@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
