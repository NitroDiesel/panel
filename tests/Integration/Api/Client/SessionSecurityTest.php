<?php

namespace Pterodactyl\Tests\Integration\Api\Client;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Http\Middleware\EnforceAbsoluteSessionLifetime;

class SessionSecurityTest extends ClientApiIntegrationTestCase
{
    public function testSessionIsRegeneratedWhenTwoFactorIsEnabled(): void
    {
        $user = User::factory()->create(['use_totp' => false]);
        $this->actingAs($user)->getJson('/api/client/account/two-factor')->assertOk();
        $code = $this->app->make(Google2FA::class)->getCurrentOtp(decrypt($user->refresh()->totp_secret));

        $this->assertSessionRegenerated(function (string $cookie) use ($user, $code) {
            return $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $cookie)
                ->postJson('/api/client/account/two-factor', ['code' => $code, 'password' => 'password'])
                ->assertOk();
        });
    }

    public function testSessionIsRegeneratedWhenTwoFactorIsDisabled(): void
    {
        $user = User::factory()->create(['use_totp' => true]);

        $this->assertSessionRegenerated(function (string $cookie) use ($user) {
            return $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $cookie)
                ->postJson('/api/client/account/two-factor/disable', ['password' => 'password'])
                ->assertNoContent();
        });
    }

    public function testSessionIsRegeneratedWhenEmailIsChanged(): void
    {
        $user = User::factory()->create();

        $this->assertSessionRegenerated(function (string $cookie) use ($user) {
            return $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $cookie)
                ->putJson('/api/client/account/email', ['email' => 'changed@example.com', 'password' => 'password'])
                ->assertNoContent();
        });
    }

    public function testSessionIsKeptWhenTheChangeIsRejected(): void
    {
        $user = User::factory()->create(['use_totp' => true]);
        $id = $this->createSession();

        $response = $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $id)
            ->postJson('/api/client/account/two-factor/disable', ['password' => 'wrong'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame($id, $this->sessionIdFrom($response));
    }

    /**
     * A session that is used every two hours is still ended once it is older than the
     * absolute lifetime, even though it never sits idle.
     */
    public function testActiveSessionEndsAtAbsoluteLifetime(): void
    {
        config()->set('session.absolute_lifetime', 240);
        $id = $this->createSession(User::factory()->create());

        $account = fn () => $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson('/api/client/account');

        $account()->assertOk();
        $this->assertNotNull($this->app['session']->driver()->get(EnforceAbsoluteSessionLifetime::SESSION_KEY));

        $this->travel(2)->hours();
        $account()->assertOk();

        $this->travel(2)->hours();
        $account()->assertUnauthorized();
    }

    public function testSessionsLastAsBeforeWithoutAbsoluteLifetime(): void
    {
        config()->set('session.absolute_lifetime', null);
        $id = $this->createSession(User::factory()->create());

        foreach (range(1, 4) as $ignored) {
            $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson('/api/client/account')->assertOk();
            $this->travel(2)->hours();
        }

        $this->assertNull($this->app['session']->driver()->get(EnforceAbsoluteSessionLifetime::SESSION_KEY));
    }

    /**
     * Runs the request with an existing session and asserts that the response hands out
     * a new session ID and that the old session no longer exists.
     */
    private function assertSessionRegenerated(\Closure $request): void
    {
        $id = $this->createSession();

        /** @var TestResponse $response */
        $response = $request($id);
        $new = $this->sessionIdFrom($response);

        $this->assertNotNull($new);
        $this->assertNotSame($id, $new);
        $this->assertSame('', $this->app['session']->driver()->getHandler()->read($id));
    }

    /**
     * Creates a session, signed in as the given user the same way a login does when one
     * is provided, and returns its ID.
     */
    private function createSession(?User $user = null): string
    {
        $session = $this->app['session']->driver();
        $session->start();
        $session->put('started', true);
        if ($user) {
            $session->put($this->app['auth']->guard('web')->getName(), $user->id);
        }
        $session->save();

        return $session->getId();
    }

    private function sessionIdFrom(TestResponse $response): ?string
    {
        return $response->getCookie(config('session.cookie'))?->getValue();
    }
}
