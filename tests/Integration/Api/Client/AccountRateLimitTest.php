<?php

namespace Pterodactyl\Tests\Integration\Api\Client;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

class AccountRateLimitTest extends ClientApiIntegrationTestCase
{
    /**
     * Requests are refused once the limit is reached, whether or not they would have
     * succeeded. Wrong current passwords keep these requests free of side effects.
     */
    public function testPasswordChangesAreLimitedPerUser(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 10; ++$i) {
            $this->assertNotThrottled($this->changePassword($user));
        }

        $this->assertThrottled($this->changePassword($user));

        // The limit belongs to this user and this action only.
        $this->assertNotThrottled($this->changePassword(User::factory()->create()));
        $this->assertNotThrottled(
            $this->actingAs($user)->postJson('/api/client/account/api-keys', ['description' => 'x', 'allowed_ips' => []])
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveRequestDataProvider')]
    public function testSensitiveAccountActionsAreLimited(string $method, string $uri, array $data): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 10; ++$i) {
            $this->assertNotThrottled($this->actingAs($user)->json($method, $uri, $data));
        }

        $this->assertThrottled($this->actingAs($user)->json($method, $uri, $data));
    }

    public static function sensitiveRequestDataProvider(): array
    {
        return [
            'email' => ['PUT', '/api/client/account/email', ['email' => 'new@example.com', 'password' => 'wrong']],
            'two-factor enable' => ['POST', '/api/client/account/two-factor', ['code' => '000000', 'password' => 'wrong']],
            'two-factor disable' => ['POST', '/api/client/account/two-factor/disable', ['password' => 'wrong']],
            'api key' => ['POST', '/api/client/account/api-keys', ['description' => '']],
            'ssh key' => ['POST', '/api/client/account/ssh-keys', ['name' => '', 'public_key' => '']],
        ];
    }

    private function changePassword(User $user): TestResponse
    {
        return $this->actingAs($user)->putJson('/api/client/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
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
