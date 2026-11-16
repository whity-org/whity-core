<?php

declare(strict_types=1);

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\BillingCatalogueCommand;

/**
 * `--check` is how a catalogue is proved correct without touching an install,
 * so none of these cases may need a database.
 */
final class BillingCatalogueCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'catalogue');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTheExampleFilePassesCheck(): void
    {
        [$code, $output] = $this->run_(__DIR__ . '/../../ops/billing-catalogue.example.json', '--check');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('4 plan(s)', $output);
    }

    public function testEveryProblemIsListed(): void
    {
        file_put_contents($this->file, (string) json_encode(['plans' => [
            ['plan_key' => 'one', 'name' => 'One', 'limits' => ['nope.key' => 1]],
            ['plan_key' => 'two', 'name' => '', 'limits' => []],
        ]]));

        [$code, $output] = $this->run_($this->file, '--check');

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown entitlement key "nope.key"', $output);
        self::assertStringContainsString('plan "two" needs a name', $output);
    }

    public function testBrokenJsonIsRefused(): void
    {
        file_put_contents($this->file, '{ not json');

        [$code, $output] = $this->run_($this->file, '--check');

        self::assertSame(1, $code);
        self::assertStringContainsString('not valid JSON', $output);
    }

    public function testAMissingFileIsRefused(): void
    {
        [$code, $output] = $this->run_($this->file . '.absent', '--check');

        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $output);
    }

    public function testAFileMustBeNamed(): void
    {
        [$code] = $this->run_('--check');

        self::assertSame(1, $code);
    }

    /** @return array{0: int, 1: string} */
    private function run_(string ...$argv): array
    {
        ob_start();
        $code = (new BillingCatalogueCommand())->execute(array_values($argv));

        return [$code, (string) ob_get_clean()];
    }
}
