<?php

namespace Pterodactyl\Http;

use Pterodactyl\Models\Node;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Builds the Content-Security-Policy for the current request and holds the nonce that
 * inline scripts must carry (rendered in views with @cspNonce).
 *
 * The policy is controlled with "http.csp.mode": "off" (the default) sends nothing,
 * "report" sends it as Content-Security-Policy-Report-Only so violations are reported
 * without blocking anything, and "enforce" blocks them.
 */
class ContentSecurityPolicy
{
    public const MODE_OFF = 'off';
    public const MODE_REPORT = 'report';
    public const MODE_ENFORCE = 'enforce';

    /**
     * Directives that administrators can extend with APP_CSP_EXTRA_*_SRC.
     */
    public const EXTENSIBLE = ['script-src', 'style-src', 'img-src', 'connect-src', 'font-src', 'frame-src'];

    /**
     * The most plain HTTP nodes whose origins are listed individually in connect-src.
     */
    public const MAX_LISTED_NODES = 50;

    private ?string $nonce = null;

    public function __construct(private Repository $config, private CacheRepository $cache)
    {
    }

    /**
     * The nonce for inline scripts in this response.
     */
    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Starts a new request; every response gets its own nonce.
     */
    public function refreshNonce(): void
    {
        $this->nonce = null;
    }

    public function mode(): string
    {
        $mode = $this->config->get('http.csp.mode');

        return in_array($mode, [self::MODE_REPORT, self::MODE_ENFORCE], true) ? $mode : self::MODE_OFF;
    }

    /**
     * The name of the header to send the policy in, or null when it is turned off.
     */
    public function headerName(): ?string
    {
        return match ($this->mode()) {
            self::MODE_REPORT => 'Content-Security-Policy-Report-Only',
            self::MODE_ENFORCE => 'Content-Security-Policy',
            default => null,
        };
    }

    public function policy(): string
    {
        // The login form loads reCAPTCHA from recaptcha.net (reaptcha), which then pulls its
        // code from gstatic.com and renders its challenge in a frame.
        $recaptcha = ['https://recaptcha.net/recaptcha/', 'https://www.recaptcha.net/recaptcha/', 'https://www.google.com/recaptcha/'];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$this->nonce()}'", ...$recaptcha, 'https://www.gstatic.com/recaptcha/'],
            // styled-components injects <style> elements at runtime.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://cdnjs.cloudflare.com'],
            'font-src' => ["'self'", 'data:', 'https://cdnjs.cloudflare.com'],
            'img-src' => ["'self'", 'data:', 'https:'],
            // Wings nodes live on other hosts: websockets for the console, HTTPS for files.
            'connect-src' => ["'self'", 'wss:', 'https:', ...$this->insecureNodeOrigins()],
            'frame-src' => $recaptcha,
            'frame-ancestors' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        foreach (self::EXTENSIBLE as $directive) {
            $extra = preg_split('/\s+/', trim((string) $this->config->get("http.csp.extra.$directive")), -1, PREG_SPLIT_NO_EMPTY);
            // A source can never contain these, and they would let a value add whole directives.
            $extra = array_filter($extra, fn (string $source) => !preg_match('/[;,]/', $source));
            $directives[$directive] = array_values(array_unique([...$directives[$directive], ...$extra]));
        }

        $policy = [];
        foreach ($directives as $directive => $sources) {
            $policy[] = $directive . ' ' . implode(' ', $sources);
        }
        $policy[] = 'report-uri ' . $this->config->get('http.csp.report_uri', '/csp-report');

        return implode('; ', $policy);
    }

    /**
     * Nodes served over plain HTTP aren't covered by "wss:" and "https:", so their exact
     * origins are allowed instead of every insecure origin. Past MAX_LISTED_NODES nodes
     * the header would outgrow what proxies typically buffer, so the schemes are allowed
     * instead.
     *
     * @return string[]
     */
    private function insecureNodeOrigins(): array
    {
        return $this->cache->remember('csp:insecure-node-origins', 60, function () {
            $nodes = Node::query()->where('scheme', 'http')->get(['fqdn', 'daemonListen']);
            if ($nodes->count() > self::MAX_LISTED_NODES) {
                return ['http:', 'ws:'];
            }

            return $nodes
                ->flatMap(fn (Node $node) => ["http://{$node->fqdn}:{$node->daemonListen}", "ws://{$node->fqdn}:{$node->daemonListen}"])
                ->filter(fn (string $origin) => !preg_match('/[\s;,\']/', $origin))
                ->unique()
                ->values()
                ->all();
        });
    }
}
