<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Base;

use Illuminate\Http\Response;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class HealthControllerTest extends HttpTestCase
{
    /**
     * The endpoint reports healthy when the database and cache both respond, and does
     * so without starting a session or handing out any cookies.
     */
    public function testHealthyResponse(): void
    {
        $response = $this->get('/up');

        $response->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function testUnhealthyWhenDatabaseFails(): void
    {
        $this->mock(ConnectionInterface::class)
            ->expects('select')
            ->andThrow(new \PDOException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->get('/up')
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertExactJson(['status' => 'fail'])
            ->assertHeaderMissing('Set-Cookie');
    }

    public function testUnhealthyWhenCacheFails(): void
    {
        $this->mock(CacheRepository::class)
            ->expects('get')
            ->andThrow(new \RuntimeException('Connection refused [tcp://cache:6379]'));

        $this->get('/up')
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertExactJson(['status' => 'fail']);
    }

    /**
     * The catch-all React route must not swallow the health check, and the endpoint must
     * not accept anything but reads.
     */
    public function testOnlyGetIsRouted(): void
    {
        $this->get('/up')->assertHeader('Content-Type', 'application/json');
        $this->post('/up')->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
