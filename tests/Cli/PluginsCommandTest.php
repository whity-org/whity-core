<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\PluginsCommand;
use Whity\Core\Response;

/**
 * Tests for PluginsCommand
 */
class PluginsCommandTest extends TestCase
{
    /**
     * Test plugin list command
     */
    public function testPluginList(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                [
                    'id' => 'AdminStats',
                    'name' => 'AdminStats',
                    'enabled' => true,
                    'route' => '/api/admin/stats',
                    'method' => 'GET'
                ]
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('GET', '/api/plugins')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('AdminStats', $output);
        $this->assertStringContainsString('Enabled', $output);
    }

    /**
     * Test plugin enable command
     */
    public function testPluginEnable(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => ['message' => 'Plugin AdminStats enabled']
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/AdminStats/enable')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['enable', 'AdminStats']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('enabled successfully', $output);
    }

    public function testUninstallCommandDryRunPrintsPlan(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                'plugin' => 'HelloWorld',
                'status' => 'active',
                'migrations_to_roll_back' => ['plugin:HelloWorld:CreateHelloTable'],
                'directory' => '/app/plugins/HelloWorld',
                'will_remove_directory' => true,
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/HelloWorld/uninstall', ['dry_run' => true, 'force' => false, 'now' => false])
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['uninstall', 'HelloWorld', '--dry-run']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('HelloWorld', $output);
        $this->assertStringContainsString('dry-run', strtolower($output));
    }

    public function testUninstallCommandExecutesAndReportsResult(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                'plugin' => 'HelloWorld',
                'disabled' => true,
                'migrations_rolled_back' => [],
                'directory_removed' => true,
                'errors' => [],
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/HelloWorld/uninstall', ['dry_run' => false, 'force' => false, 'now' => false])
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['uninstall', 'HelloWorld']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('uninstalled', strtolower($output));
    }

    public function testUninstallCommandReturnsNonZeroOnError(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(409, json_encode([
            'data' => [
                'plugin' => 'BadPlugin',
                'disabled' => true,
                'migrations_rolled_back' => [],
                'directory_removed' => false,
                'errors' => ['Failed to remove tracking row'],
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/BadPlugin/uninstall', ['dry_run' => false, 'force' => false, 'now' => false])
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['uninstall', 'BadPlugin']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('error', strtolower($output));
    }

    /** #1907: approving an existing role for a plugin goes through the app's API. */
    public function testRoleApproveCallsTheApproveRoute(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/HelloWorld/roles/hello_viewer/approve', ['tenant_id' => 3])
            ->willReturn(new Response(200, (string) json_encode(['data' => ['already_allowed' => false]])));

        ob_start();
        $exitCode = $command->execute(['role-approve', 'HelloWorld', 'hello_viewer', '--tenant', '3']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Approved: HelloWorld', $output);
    }

    public function testRoleApproveWithoutATenantApprovesTheGlobalRole(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/HelloWorld/roles/hello_viewer/approve', [])
            ->willReturn(new Response(403, '{"error":"Plugins are installed and changed by the platform operator."}'));

        ob_start();
        $exitCode = $command->execute(['role-approve', 'HelloWorld', 'hello_viewer']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('platform operator', $output);
    }

    public function testRoleApproveNeedsAPluginAndARole(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();
        $command->expects($this->never())->method('callApi');

        ob_start();
        $missing = $command->execute(['role-approve', 'HelloWorld']);
        $badTenant = $command->execute(['role-approve', 'HelloWorld', 'hello_viewer', '--tenant', 'x']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $missing);
        $this->assertSame(1, $badTenant);
        $this->assertStringContainsString('Usage: whity-cli plugin role-approve', $output);
    }
}
