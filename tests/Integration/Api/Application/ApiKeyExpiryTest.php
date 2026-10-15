<?php

namespace Pterodactyl\Tests\Integration\Api\Application;

use Carbon\CarbonImmutable;

class ApiKeyExpiryTest extends ApplicationApiIntegrationTestCase
{
    public function testUnexpiredKeyIsAccepted(): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['expires_at' => CarbonImmutable::now()->addDay()]);

        $this->getJson('/api/application/users')->assertOk();
    }

    public function testExpiredKeyIsRejected(): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['expires_at' => CarbonImmutable::now()->subMinute()]);

        $this->getJson('/api/application/users')->assertUnauthorized();
    }
}
