<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Sandbox;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Mail\LogMailer;
use Whity\Core\Sandbox\SideEffectBlockedException;
use Whity\Core\Sandbox\SideEffectGuard;

/**
 * Nothing leaves a proposal preview but its return value (WHIT-991): mail and
 * hook events are refused while the guard runs, and allowed again after it,
 * even when the guarded code threw.
 */
final class SideEffectGuardTest extends TestCase
{
    public function testEventsAndMailAreRefusedOnlyWhileItRuns(): void
    {
        $hooks = new HookManager();
        $heard = 0;
        $hooks->listen('acme:thing.happened', static function (array $p) use (&$heard): array {
            $heard++;
            return $p;
        });

        $refused = [];
        SideEffectGuard::blocking('a proposal preview', static function () use ($hooks, &$refused): null {
            foreach ([
                'event' => static fn () => $hooks->dispatch('acme:thing.happened', []),
                'async' => static fn () => $hooks->dispatchAsync('acme:thing.happened', []),
                'mail'  => static fn () => (new LogMailer(new NullLogger()))->send('rana@example.test', 'Hi', 'Hello'),
            ] as $what => $try) {
                try {
                    $try();
                } catch (SideEffectBlockedException $e) {
                    $refused[$what] = $e->getMessage();
                }
            }
            return null;
        });

        self::assertSame(['event', 'async', 'mail'], array_keys($refused));
        self::assertSame('Dispatching acme:thing.happened is not allowed during a proposal preview', $refused['event']);
        self::assertSame(0, $heard);
        self::assertFalse(SideEffectGuard::isBlocking());

        $hooks->dispatch('acme:thing.happened', []);
        self::assertSame(1, $heard, 'allowed again afterwards');
    }

    public function testAThrowDoesNotLeaveTheWorkerBlocked(): void
    {
        try {
            SideEffectGuard::blocking('a proposal preview', static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        self::assertFalse(SideEffectGuard::isBlocking());
        SideEffectGuard::assertAllowed('Anything');
        $this->addToAssertionCount(1);
    }
}
