<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Http\Middleware\SetSecurityHeaders;

class SetSecurityHeadersTest extends TestCase
{
    public function testDefaultHeadersAreSet(): void
    {
        $response = (new SetSecurityHeaders())->handle(Request::create('/'), fn () => new Response('ok'));

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // The XSS auditor this enabled is gone from browsers and could itself be abused,
        // so it is explicitly disabled.
        $this->assertSame('0', $response->headers->get('X-XSS-Protection'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
    }

    public function testHeadersAlreadyOnTheResponseAreKept(): void
    {
        $response = (new SetSecurityHeaders())->handle(Request::create('/'), function () {
            return new Response('ok', 200, ['X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'no-referrer']);
        });

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
