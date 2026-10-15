<?php

namespace Pterodactyl\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;

class SessionCookieTest extends HttpTestCase
{
    /**
     * The session cookie is marked secure by default when the panel is served over
     * HTTPS, and SESSION_SECURE_COOKIE overrides that either way.
     */
    #[DataProvider('secureCookieDataProvider')]
    public function testSecureCookieDefault(string $appUrl, ?string $explicit, bool $expected): void
    {
        $config = $this->withEnvironment(['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $explicit], function () {
            return require config_path('session.php');
        });

        $this->assertSame($expected, (bool) $config['secure']);
    }

    public static function secureCookieDataProvider(): array
    {
        return [
            'https, unset' => ['https://panel.example.com', null, true],
            'http, unset' => ['http://panel.example.com', null, false],
            'https, explicitly off' => ['https://panel.example.com', 'false', false],
            'http, explicitly on' => ['http://panel.example.com', 'true', true],
        ];
    }

    public function testSessionCookieCarriesTheSecureFlag(): void
    {
        $config = $this->withEnvironment(['APP_URL' => 'https://panel.example.com', 'SESSION_SECURE_COOKIE' => null], function () {
            return require config_path('session.php');
        });
        config()->set('session.secure', $config['secure']);

        $cookie = $this->get('/auth/login')->getCookie(config('session.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    /**
     * Browsers only accept a __Host- prefixed cookie when it is secure, has a path of /
     * and no domain, so opting in with SESSION_COOKIE must produce exactly that.
     */
    public function testHostPrefixedSessionCookieIsAccepted(): void
    {
        config()->set('session.cookie', '__Host-pterodactyl_session');
        config()->set('session.secure', true);

        $cookie = $this->get('/auth/login')->getCookie('__Host-pterodactyl_session', false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
    }

    /**
     * Evaluates the callback with the given environment variables set (or removed, for
     * null values), restoring the previous environment afterwards.
     */
    private function withEnvironment(array $values, \Closure $callback): mixed
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            if (is_null($value)) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => [$server, $env, $putenv]) {
                is_null($server) ? $_SERVER = array_diff_key($_SERVER, [$key => true]) : $_SERVER[$key] = $server;
                is_null($env) ? $_ENV = array_diff_key($_ENV, [$key => true]) : $_ENV[$key] = $env;
                $putenv === false ? putenv($key) : putenv("$key=$putenv");
            }
        }
    }
}
