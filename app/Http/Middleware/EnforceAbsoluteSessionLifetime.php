<?php

namespace Pterodactyl\Http\Middleware;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Config\Repository;

/**
 * Ends signed in sessions once they are older than "session.absolute_lifetime" minutes,
 * no matter how active they are. The session lifetime setting is only an idle timeout,
 * so without this a session that is used regularly never expires. Disabled when the
 * setting is empty.
 */
class EnforceAbsoluteSessionLifetime
{
    public const SESSION_KEY = 'auth.session_started_at';

    public function __construct(private AuthManager $auth, private Repository $config)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        $lifetime = (int) $this->config->get('session.absolute_lifetime');
        if ($lifetime <= 0 || !$request->hasSession()) {
            return $next($request);
        }

        $guard = $this->auth->guard('web');
        if (!$guard->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = CarbonImmutable::now()->getTimestamp();
        $startedAt = $session->get(self::SESSION_KEY);

        if (is_null($startedAt)) {
            // The first authenticated request of this session, or one that predates
            // this setting being turned on.
            $session->put(self::SESSION_KEY, $now);
        } elseif ($now - (int) $startedAt >= $lifetime * 60) {
            $guard->logout();
            $session->invalidate();
            $session->regenerateToken();

            // Guards resolved earlier in this request (such as Sanctum's, which may already
            // have looked up the user) would otherwise keep the signed out user.
            $this->auth->forgetGuards();
        }

        return $next($request);
    }
}
