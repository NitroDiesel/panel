<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Auth;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Session;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

/**
 * The controllers also lock out repeated failures for the same username and IP. These
 * tests vary the IP (or the username) between attempts so that only the route rate
 * limiters can respond with a 429, and assert on Retry-After, which only they send.
 */
class AuthenticationRateLimitTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('recaptcha.enabled', false);
    }

    public function testLoginIsLimitedPerAccountAcrossAddresses(): void
    {
        User::factory()->create(['username' => 'target']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->assertNotThrottled($this->login(' Target ', "10.0.0.$i"));
        }

        $this->assertThrottled($this->login('target', '10.0.0.6'));

        // Other accounts are not affected by the limit on this one.
        $this->assertNotThrottled($this->login('someone-else', '10.0.0.7'));
    }

    public function testCheckpointIsLimitedPerPendingLoginAcrossAddresses(): void
    {
        $this->startCheckpoint(User::factory()->create(['use_totp' => true]));

        for ($i = 1; $i <= 5; ++$i) {
            $this->assertNotThrottled($this->checkpoint("10.0.2.$i"));
        }

        $this->assertThrottled($this->checkpoint('10.0.2.6'));

        // A different pending login from a fresh address is not limited.
        $this->startCheckpoint(User::factory()->create(['use_totp' => true]));
        $this->assertNotThrottled($this->checkpoint('10.0.2.7'));
    }

    public function testCheckpointIsLimitedPerAddress(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->startCheckpoint(User::factory()->create(['use_totp' => true]));
            $this->assertNotThrottled($this->checkpoint('10.0.3.1'));
        }

        $this->startCheckpoint(User::factory()->create(['use_totp' => true]));
        $this->assertThrottled($this->checkpoint('10.0.3.1'));
    }

    private function login(string $username, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/auth/login', ['user' => $username, 'password' => 'wrong-password']);
    }

    private function startCheckpoint(User $user): void
    {
        Session::put('auth_confirmation_token', [
            'user_id' => $user->id,
            'token_value' => 'token',
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function checkpoint(string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('auth.login-checkpoint'), [
                'confirmation_token' => 'token',
                'authentication_code' => '000000',
            ]);
    }

    private function assertNotThrottled(TestResponse $response): void
    {
        $this->assertNotSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    private function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }
}
