<?php

declare(strict_types=1);

namespace Tests\Sdk\Frontend;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Frontend\FrameBridge;

/**
 * The page side of the embedded-screen bridge (#1688) speaks the protocol the
 * web app's `lib/plugin-frame-bridge.ts` listens for.
 */
final class FrameBridgeTest extends TestCase
{
    public function testTheProtocolMatchesTheWebApp(): void
    {
        $web = (string) file_get_contents(__DIR__ . '/../../../web/lib/plugin-frame-bridge.ts');

        self::assertStringContainsString("export const FRAME_PROTOCOL = '" . FrameBridge::PROTOCOL . "';", $web);
    }

    public function testTheScriptDefinesTheClientAndSaysReady(): void
    {
        $script = FrameBridge::script();

        self::assertStringContainsString('var P = "' . FrameBridge::PROTOCOL . '", parentWin', $script);
        self::assertStringContainsString('window.whity = {', $script);
        foreach (['ready', 'request', 'resize', 'navigate', 'toast'] as $method) {
            self::assertStringContainsString("{$method}: function", $script);
        }
        // It only listens to the app that framed it, and never posts a token.
        self::assertStringContainsString('e.source !== parentWin', $script);
        self::assertStringNotContainsString('Authorization', $script);
        self::assertStringEndsWith("post({ type: 'ready' });\n})();", $script);
    }

    public function testTheScriptIsValidJavaScript(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'whity-frame-') . '.js';
        file_put_contents($file, FrameBridge::script());
        try {
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
        } finally {
            @unlink($file);
        }
    }
}
