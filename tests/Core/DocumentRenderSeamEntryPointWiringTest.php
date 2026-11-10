<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * #1072: the HTTP entry point must actually WIRE the SDK rendering seam, and
 * must wire it over the shared renderer rather than a second one.
 *
 * A contract nobody registers is worse than no contract: `\Whity\app()` fails
 * closed, so every plugin that adopted the seam would throw at the first call —
 * and the fallback a plugin author reaches for when that happens is the thing
 * the seam exists to remove, a renderer of its own reading its own environment
 * variables.
 *
 * Registering a SECOND FlowDocumentRenderer would be quieter and worse. The
 * ceilings, the settings chain and the client all live in that object; a
 * duplicate built from different arguments would enforce a different policy for
 * plugins than for the HTTP render path, with nothing anywhere reporting that
 * the two disagree. This is the same drift {@see PermissionResolverEntryPointWiringTest}
 * pins for the permission resolver, for the same reason.
 *
 * public/index.php cannot be executed in a unit test (it is a full worker
 * bootstrap), so the wiring is pinned by scanning its source — the technique
 * the sibling wiring tests already use.
 */
final class DocumentRenderSeamEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersTheSeamUnderTheSdkInterface(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\n?\s*\\\\?Whity\\\\Sdk\\\\Render\\\\DocumentRenderer::class/',
            $source,
            'public/index.php must register the renderer under the SDK INTERFACE name. Under a '
            . 'core class name a plugin would have to type-hint a core type, which the SDK '
            . 'contract test forbids it from even referencing.'
        );
    }

    public function testTheSeamIsBuiltOverTheHostsOwnRendererAndIssuer(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new\s+\\\\?Whity\\\\Core\\\\Document\\\\Render\\\\SdkDocumentRenderer\(\s*\n?\s*\$flowDocumentRenderer\s*,\s*\n?\s*\$documentIssuer\s*,/',
            $source,
            'The seam must be built over the SAME $documentIssuer the HTTP render path uses, so a '
            . 'plugin-issued document gets the identical storage routing, immutability rule and '
            . 'provenance stamping as one a person issues.'
        );
    }

    public function testExactlyOneFlowRendererIsConstructed(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertSame(
            1,
            preg_match_all('/new\s+\\\\?Whity\\\\Core\\\\Document\\\\Render\\\\FlowDocumentRenderer\(/', $source),
            'Exactly one FlowDocumentRenderer may be constructed. A second one would carry its own '
            . 'settings service and client, so plugins and HTTP callers could end up enforcing '
            . 'different ceilings against different render services with nothing reporting it.'
        );
    }

    /**
     * #1151: the CLI kernel and `queue:work` register the seam too, under the
     * SDK interface, over the process's one document stack.
     */
    public function testTheCliKernelRegistersTheSeamOverItsDocumentStack(): void
    {
        $cli = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\n?\s*\\\\?Whity\\\\Sdk\\\\Render\\\\DocumentRenderer::class,\s*\n?\s*new\s+\\\\?Whity\\\\Core\\\\Document\\\\Render\\\\DeferredSdkDocumentRenderer\(\s*\n?\s*static fn \(\): \\\\?Whity\\\\Sdk\\\\Render\\\\DocumentRenderer => \$documentParts->sdkRenderer\(\)/',
            $cli,
            'BaseCommand::setupKernel() must register the rendering seam over $documentParts, the same '
            . 'stack its template issuing uses.'
        );
        self::assertMatchesRegularExpression(
            '/WorkerDocumentIssuing::build\([^;]*\$documentParts,\s*\)/s',
            $cli,
            'The CLI kernel\'s template issuing must be built over the same $documentParts.'
        );
    }

    public function testTheQueueWorkerRegistersTheSeamOverItsDocumentStack(): void
    {
        $worker = $this->read(__DIR__ . '/../../src/Core/Document/Issuing/WorkerDocumentServices.php');

        self::assertStringContainsString('\\Whity\\Sdk\\Render\\DocumentRenderer::class', $worker);
        self::assertStringContainsString('$parts->sdkRenderer()', $worker);
        self::assertMatchesRegularExpression('/WorkerDocumentIssuing::build\([^;]*\$parts\)/', $worker);
    }

    public function testTheContractNoLongerCallsTheSeamHttpOnly(): void
    {
        foreach (['sdk/src/Render/DocumentRenderer.php', 'docs/wiki/Plugin-Development.md', 'sdk/README.md'] as $file) {
            self::assertStringNotContainsString(
                'HTTP entry point only',
                $this->read(__DIR__ . '/../../' . $file),
                "{$file} still says the rendering seam is HTTP-only; it is wired in jobs and commands (#1151)."
            );
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
