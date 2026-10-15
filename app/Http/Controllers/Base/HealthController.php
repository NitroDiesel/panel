<?php

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class HealthController extends Controller
{
    public function __construct(private ConnectionInterface $connection, private CacheRepository $cache)
    {
    }

    /**
     * Reports whether the Panel can reach its database and cache, for use by container
     * healthchecks and load balancers. This route is registered outside of the "web" and
     * "api" middleware groups so probing it never starts a session or requires auth, and
     * the response intentionally contains nothing about the environment.
     */
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->select('select 1');
            $this->cache->get('pterodactyl:health');
        } catch (\Throwable $exception) {
            Log::warning('Health check failed.', ['exception' => $exception]);

            return $this->respond('fail', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->respond('ok', Response::HTTP_OK);
    }

    private function respond(string $status, int $code): JsonResponse
    {
        return new JsonResponse(['status' => $status], $code, ['Cache-Control' => 'no-store']);
    }
}
