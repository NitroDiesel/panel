<?php

namespace Pterodactyl\Tests\Integration\Http;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\Location;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Finder\Finder;
use Pterodactyl\Http\ContentSecurityPolicy;

class ContentSecurityPolicyTest extends HttpTestCase
{
    private const POLICY_HEADERS = ['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'Cross-Origin-Opener-Policy', 'Strict-Transport-Security'];

    public function testNothingChangesWhenTurnedOff(): void
    {
        config()->set('http.csp.mode', 'off');

        $response = $this->get('/auth/login')->assertOk();

        foreach (self::POLICY_HEADERS as $header) {
            $response->assertHeaderMissing($header);
        }
        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-XSS-Protection', '0')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    public function testReportModeSendsAReportOnlyPolicy(): void
    {
        config()->set('http.csp.mode', 'report');

        $response = $this->get('/auth/login')->assertOk();

        $response->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Cross-Origin-Opener-Policy');
        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertInlineScriptsCarryTheNonce($response, $policy);
    }

    public function testEnforceModeSendsThePolicyAndIsolatesTheWindow(): void
    {
        config()->set('http.csp.mode', 'enforce');

        $response = $this->get('/auth/login')->assertOk();

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertInlineScriptsCarryTheNonce($response, $response->headers->get('Content-Security-Policy'));
    }

    public function testAdminPagesCarryTheNonce(): void
    {
        config()->set('http.csp.mode', 'enforce');
        // The admin layout prints the render time, measured from public/index.php.
        defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));

        $response = $this->actingAs(User::factory()->create(['root_admin' => true]))->get('/admin')->assertOk();

        $this->assertInlineScriptsCarryTheNonce($response, $response->headers->get('Content-Security-Policy'));
    }

    /**
     * Variables passed to JavaScript::put() are written into the page by laracasts/utilities
     * as an inline script, which must carry the nonce as well.
     */
    public function testInjectedJavascriptVariablesCarryTheNonce(): void
    {
        config()->set('http.csp.mode', 'enforce');
        defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));

        $response = $this->actingAs(User::factory()->create(['root_admin' => true]))->get('/admin/nests/egg/new')->assertOk();

        $this->assertStringContainsString('window.Pterodactyl', $response->getContent());
        $this->assertInlineScriptsCarryTheNonce($response, $response->headers->get('Content-Security-Policy'));
    }

    public function testEveryResponseGetsANewNonce(): void
    {
        config()->set('http.csp.mode', 'enforce');

        $first = $this->nonceFrom($this->get('/auth/login')->headers->get('Content-Security-Policy'));
        $second = $this->nonceFrom($this->get('/auth/login')->headers->get('Content-Security-Policy'));

        $this->assertNotSame($first, $second);
    }

    public function testAdministratorsCanAddSources(): void
    {
        config()->set('http.csp.mode', 'enforce');
        config()->set('http.csp.extra.connect-src', 'ws://node.example.com:8080  https://wings.example.com');
        config()->set('http.csp.extra.script-src', 'https://cdn.example.com');

        $policy = $this->get('/auth/login')->headers->get('Content-Security-Policy');

        $this->assertStringEndsWith(' ws://node.example.com:8080 https://wings.example.com', $this->directive($policy, 'connect-src'));
        $this->assertStringEndsWith(' https://cdn.example.com', $this->directive($policy, 'script-src'));
    }

    /**
     * The console connects to Wings with a websocket. Nodes on HTTPS are covered by wss:,
     * nodes on plain HTTP need their exact origins.
     */
    public function testPlainHttpNodesAreAllowedToConnect(): void
    {
        config()->set('http.csp.mode', 'enforce');
        $location = Location::factory()->create();
        Node::factory()->create(['location_id' => $location->id, 'scheme' => 'http', 'fqdn' => 'insecure.example.com', 'daemonListen' => 8080]);
        Node::factory()->create(['location_id' => $location->id, 'scheme' => 'https', 'fqdn' => 'secure.example.com', 'daemonListen' => 8443]);

        $sources = array_slice(explode(' ', $this->directive($this->get('/auth/login')->headers->get('Content-Security-Policy'), 'connect-src')), 1);

        $this->assertSame(["'self'", 'wss:', 'https:'], array_slice($sources, 0, 3));
        $this->assertContains('http://insecure.example.com:8080', $sources);
        $this->assertContains('ws://insecure.example.com:8080', $sources);
        $this->assertEmpty(array_filter($sources, fn (string $source) => str_contains($source, 'secure.example.com:8443')));
    }

    public function testManyPlainHttpNodesFallBackToTheirSchemes(): void
    {
        config()->set('http.csp.mode', 'enforce');
        $location = Location::factory()->create();
        Node::factory()->times(ContentSecurityPolicy::MAX_LISTED_NODES + 1)->create(['location_id' => $location->id, 'scheme' => 'http']);

        $connect = $this->directive($this->get('/auth/login')->headers->get('Content-Security-Policy'), 'connect-src');

        $this->assertSame("connect-src 'self' wss: https: http: ws:", $connect);
    }

    /**
     * Extra sources are split on whitespace, and anything containing a ";" or "," is
     * dropped, so a value can never end its directive and add or replace another one.
     */
    public function testExtraSourcesCannotAddDirectives(): void
    {
        config()->set('http.csp.mode', 'enforce');
        config()->set('http.csp.extra.script-src', 'https://ok.example.com;report-uri https://evil.example ;default-src *');
        config()->set('http.csp.extra.img-src', 'x, frame-ancestors *');

        $policy = $this->get('/auth/login')->headers->get('Content-Security-Policy');
        $directives = array_map(fn (string $segment) => explode(' ', trim($segment))[0], explode(';', $policy));

        $this->assertSame([
            'default-src', 'script-src', 'style-src', 'font-src', 'img-src', 'connect-src', 'frame-src',
            'frame-ancestors', 'object-src', 'base-uri', 'form-action', 'report-uri',
        ], $directives);
        $this->assertStringEndsWith('; report-uri /csp-report', $policy);
        $this->assertStringContainsString("default-src 'self';", $policy);
        $this->assertStringContainsString("frame-ancestors 'none';", $policy);
    }

    public function testStrictTransportSecurityOnlyForSecureRequestsWhenEnabled(): void
    {
        config()->set('http.hsts', true);
        $this->get('https://localhost/auth/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/auth/login')->assertHeaderMissing('Strict-Transport-Security');

        config()->set('http.hsts', false);
        $this->get('https://localhost/auth/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function testViolationReportsAreLoggedWithoutASession(): void
    {
        Log::spy();

        $response = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri' => 'https://panel.example.com/server/abc',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'inline',
                'original-policy' => str_repeat('x', 5000),
            ],
        ]));

        $response->assertStatus(Response::HTTP_NO_CONTENT)->assertHeaderMissing('Set-Cookie');
        Log::shouldHaveReceived('debug')->once()->withArgs(function (string $message, array $context) {
            return $context === [
                'document-uri' => 'https://panel.example.com/server/abc',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'inline',
            ];
        });
    }

    /**
     * Guards against inline scripts being added to a view without the nonce, which would
     * be blocked once the policy is enforced.
     */
    public function testEveryInlineScriptInTheViewsUsesTheNonce(): void
    {
        $missing = [];
        foreach ((new Finder())->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all('/<script\b[^>]*>/', $file->getContents(), $matches);
            foreach ($matches[0] as $tag) {
                if (!str_contains($tag, 'src=') && !str_contains($tag, '@cspNonce')) {
                    $missing[] = $file->getRelativePathname() . ': ' . $tag;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    private function assertInlineScriptsCarryTheNonce(TestResponse $response, string $policy): void
    {
        $nonce = $this->nonceFrom($policy);
        preg_match_all('/<script\b[^>]*>/', $response->getContent(), $matches);
        $inline = array_filter($matches[0], fn (string $tag) => !str_contains($tag, 'src='));

        $this->assertNotEmpty($inline);
        foreach ($inline as $tag) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag);
        }
    }

    private function directive(string $policy, string $name): string
    {
        foreach (explode(';', $policy) as $segment) {
            if (str_starts_with(trim($segment), "$name ")) {
                return trim($segment);
            }
        }

        $this->fail("The policy has no $name directive.");
    }

    private function nonceFrom(string $policy): string
    {
        $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $match);

        return $match[1];
    }
}
