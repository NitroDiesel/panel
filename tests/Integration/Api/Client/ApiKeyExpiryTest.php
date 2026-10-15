<?php

namespace Pterodactyl\Tests\Integration\Api\Client;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\ApiKey;

class ApiKeyExpiryTest extends ClientApiIntegrationTestCase
{
    public function testKeyCanBeCreatedWithAnExpiry(): void
    {
        $user = User::factory()->create();
        $expiresAt = CarbonImmutable::now()->addDays(7)->startOfSecond();

        $response = $this->actingAs($user)->postJson('/api/client/account/api-keys', [
            'description' => 'Expiring key',
            'expires_at' => $expiresAt->toAtomString(),
        ])->assertOk();

        $key = ApiKey::query()->where('identifier', $response->json('attributes.identifier'))->firstOrFail();

        $this->assertTrue($key->expires_at->equalTo($expiresAt));
        $response->assertJsonPath('attributes.expires_at', $key->expires_at->toAtomString());
    }

    public function testKeyWithoutAnExpiryNeverExpires(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/client/account/api-keys', [
            'description' => 'Permanent key',
        ])->assertOk();

        $response->assertJsonPath('attributes.expires_at', null);
        $this->assertNull(ApiKey::query()->where('identifier', $response->json('attributes.identifier'))->value('expires_at'));
    }

    public function testExpiryMustBeInTheFuture(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/client/account/api-keys', [
            'description' => 'Already expired',
            'expires_at' => CarbonImmutable::now()->subMinute()->toAtomString(),
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('errors.0.meta.source_field', 'expires_at');

        $this->assertSame(0, $user->apiKeys()->count());
    }

    public function testUnexpiredKeyIsAcceptedByTheClientApi(): void
    {
        $key = $this->createAccountKey(User::factory()->create(), CarbonImmutable::now()->addDay());

        $this->withToken($this->secret($key))->getJson('/api/client/account')->assertOk();
    }

    public function testExpiredKeyIsRejectedByTheClientApi(): void
    {
        $key = $this->createAccountKey(User::factory()->create(), CarbonImmutable::now()->subMinute());

        $this->withToken($this->secret($key))->getJson('/api/client/account')->assertUnauthorized();
    }

    private function createAccountKey(User $user, CarbonImmutable $expiresAt): ApiKey
    {
        return ApiKey::factory()->create([
            'user_id' => $user->id,
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'expires_at' => $expiresAt,
        ]);
    }

    private function secret(ApiKey $key): string
    {
        return $key->identifier . decrypt($key->token);
    }
}
