<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Contracts\Config\Repository;
use Pterodactyl\Http\ContentSecurityPolicy;

class SetSecurityHeaders
{
    /**
     * Headers sent with every response. A Content-Security-Policy is added when enabled
     * with APP_CSP_MODE (off by default, since custom plugins and embeds may need their
     * own sources first; see config/http.php).
     */
    private static array $headers = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        // Browsers have removed the XSS auditor this used to enable, and where it still
        // exists it can be abused to selectively disable scripts, so turn it off.
        'X-XSS-Protection' => '0',
        // Send the full URL as the referrer only to this origin, and nothing over HTTP.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function __construct(private ContentSecurityPolicy $csp, private Repository $config)
    {
    }

    /**
     * Enforces some basic security headers on all responses returned by the software.
     * If a header has already been set in another location within the code it will be
     * skipped over here.
     *
     * @param (\Closure(mixed): \Illuminate\Http\Response) $next
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $this->csp->refreshNonce();

        $response = $next($request);

        $headers = self::$headers;
        if ($name = $this->csp->headerName()) {
            $headers[$name] = $this->csp->policy();
        }

        // Isolating the browsing context can break flows that rely on popups, so it only
        // comes with an enforced policy.
        if ($this->csp->mode() === ContentSecurityPolicy::MODE_ENFORCE) {
            $headers['Cross-Origin-Opener-Policy'] = 'same-origin';
        }

        if ($request->isSecure() && $this->config->get('http.hsts')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
