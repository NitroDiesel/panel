<?php

namespace Pterodactyl\Tests\Integration\Http;

use Pterodactyl\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class ThemeStylesheetTest extends HttpTestCase
{
    private const LINK = '<link rel="stylesheet" href="/branding/theme.css">';

    public function setUp(): void
    {
        parent::setUp();

        // The admin layout prints the render time, measured from public/index.php.
        defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));
    }

    public function testNoStylesheetIsLinkedByDefault(): void
    {
        config()->set('branding.theme_css', null);

        $this->assertStringNotContainsString('branding/theme.css', $this->get('/auth/login')->assertOk()->getContent());
        $this->assertStringNotContainsString('branding/theme.css', $this->getAdminPage());
    }

    public function testTheStylesheetIsLinkedOnClientAndAdminPages(): void
    {
        config()->set('branding.theme_css', '/branding/theme.css');

        $this->assertStringContainsString(self::LINK, $this->get('/auth/login')->assertOk()->getContent());
        $this->assertStringContainsString(self::LINK, $this->getAdminPage());
    }

    public function testTheAdminLinkComesAfterThePanelStyles(): void
    {
        config()->set('branding.theme_css', '/branding/theme.css');

        $html = $this->getAdminPage();

        $this->assertGreaterThan(strpos($html, 'css/pterodactyl.css'), strpos($html, self::LINK));
    }

    #[DataProvider('configuredValues')]
    public function testOnlySameSitePathsAndHttpsUrlsAreAccepted(?string $value, ?string $expected): void
    {
        putenv($value === null ? 'APP_BRAND_THEME_CSS' : "APP_BRAND_THEME_CSS=$value");

        try {
            $config = require config_path('branding.php');
        } finally {
            putenv('APP_BRAND_THEME_CSS');
        }

        $this->assertSame($expected, $config['theme_css']);
    }

    public static function configuredValues(): array
    {
        return [
            'unset' => [null, null],
            'site path' => ['/branding/theme.css', '/branding/theme.css'],
            'https url' => ['https://cdn.example.com/theme.css', 'https://cdn.example.com/theme.css'],
            'protocol-relative url' => ['//cdn.example.com/theme.css', null],
            'http url' => ['http://cdn.example.com/theme.css', null],
            'javascript url' => ['javascript:alert(1)', null],
            'relative path' => ['branding/theme.css', null],
            'path with a space' => ['/branding/theme.css onload=x', null],
        ];
    }

    private function getAdminPage(): string
    {
        return $this->actingAs(User::factory()->create(['root_admin' => true]))->get('/admin')->assertOk()->getContent();
    }
}
