<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class SettingsControllerTest extends ClientApiIntegrationTestCase
{
    /**
     * Test that the server's name can be changed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renamePermissionsDataProvider')]
    public function testServerNameCanBeChanged(array $permissions)
    {
        /** @var Server $server */
        [$user, $server] = $this->generateTestAccount($permissions);
        $originalName = $server->name;
        $originalDescription = $server->description;

        $response = $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/settings/rename", [
            'name' => '',
            'description' => '',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('errors.0.meta.rule', 'required');

        $server = $server->refresh();
        $this->assertSame($originalName, $server->name);
        $this->assertSame($originalDescription, $server->description);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/settings/rename", [
                'name' => 'Test Server Name',
                'description' => 'This is a test server.',
            ])
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $server = $server->refresh();
        $this->assertSame('Test Server Name', $server->name);
        $this->assertSame('This is a test server.', $server->description);
    }

    /**
     * Test that a subuser receives a permissions error if they do not have the required permission
     * and attempt to change the name.
     */
    public function testSubuserCannotChangeServerNameWithoutPermission()
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_WEBSOCKET_CONNECT]);
        $originalName = $server->name;

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/settings/rename", [
                'name' => 'Test Server Name',
            ])
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $server = $server->refresh();
        $this->assertSame($originalName, $server->name);
    }

    /**
     * Test that a server can be reinstalled. Honestly this test doesn't do much of anything other
     * than make sure the endpoint works since.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reinstallPermissionsDataProvider')]
    public function testServerCanBeReinstalled(array $permissions)
    {
        /** @var Server $server */
        [$user, $server] = $this->generateTestAccount($permissions);
        $this->assertTrue($server->isInstalled());

        $service = \Mockery::mock(DaemonServerRepository::class);
        $this->app->instance(DaemonServerRepository::class, $service);

        $service->expects('setServer')
            ->with(\Mockery::on(function ($value) use ($server) {
                return $value->uuid === $server->uuid;
            }))
            ->andReturnSelf()
            ->getMock()
            ->expects('reinstall')
            ->andReturnUndefined();

        $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/settings/reinstall")
            ->assertStatus(Response::HTTP_ACCEPTED);

        $server = $server->refresh();
        $this->assertSame(Server::STATUS_INSTALLING, $server->status);
    }

    /**
     * Test that a subuser receives a permissions error if they do not have the required permission
     * and attempt to reinstall a server.
     */
    /**
     * Reinstalling is limited to once every five minutes per server, regardless of which
     * user asks for it.
     */
    public function testReinstallIsLimitedPerServer(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $subuser = $this->generateTestAccount([Permission::ACTION_SETTINGS_REINSTALL])[0];
        $server->subusers()->create(['user_id' => $subuser->id, 'permissions' => [Permission::ACTION_SETTINGS_REINSTALL]]);

        $this->mock(DaemonServerRepository::class)
            ->expects('setServer')->andReturnSelf()
            ->getMock()
            ->expects('reinstall')->once()->andReturnUndefined();

        $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/settings/reinstall")
            ->assertStatus(Response::HTTP_ACCEPTED);

        // Pretend the install finished; while installing, server access is refused outright.
        $server->refresh()->forceFill(['status' => null])->save();

        $response = $this->actingAs($subuser)->postJson("/api/client/servers/$server->uuid/settings/reinstall");
        $response->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function testSubuserCannotReinstallServerWithoutPermission()
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_WEBSOCKET_CONNECT]);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/settings/reinstall")
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $server = $server->refresh();
        $this->assertTrue($server->isInstalled());
    }

    /**
     * Test that a server configured to skip its egg's install script cannot be reinstalled.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reinstallPermissionsDataProvider')]
    public function testServerCannotBeReinstalledIfConfiguredToSkipScripts(array $permissions)
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $server->update(['skip_scripts' => true]);

        $service = \Mockery::mock(DaemonServerRepository::class);
        $this->app->instance(DaemonServerRepository::class, $service);

        $service->expects('setServer')->never();

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/settings/reinstall")
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.0.detail', trans('admin/server.exceptions.skipping_install_script'));

        $this->assertNull($server->refresh()->status);
    }

    /**
     * Test that the "skip scripts" state is exposed to the client API.
     */
    public function testSkipScriptsStateIsExposedToClient()
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_SETTINGS_REINSTALL]);

        $this->actingAs($user)
            ->getJson("/api/client/servers/$server->uuid")
            ->assertOk()
            ->assertJsonPath('attributes.skip_scripts', false);

        $server->update(['skip_scripts' => true]);

        $this->actingAs($user)
            ->getJson("/api/client/servers/$server->uuid")
            ->assertOk()
            ->assertJsonPath('attributes.skip_scripts', true);
    }

    public static function renamePermissionsDataProvider(): array
    {
        return [[[]], [[Permission::ACTION_SETTINGS_RENAME]]];
    }

    public static function reinstallPermissionsDataProvider(): array
    {
        return [[[]], [[Permission::ACTION_SETTINGS_REINSTALL]]];
    }
}
