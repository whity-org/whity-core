<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Frees each finished test's database straight away.
 *
 * A common setUp() is `$this->db = Database::withFactory(fn() => $this->pdo)`.
 * The closure holds the test and the test holds the closure, so the object, and
 * the in-memory SQLite database behind `$this->pdo`, stay alive until PHP's cycle
 * collector happens to run. That collector raises its own threshold whenever a
 * pass finds little, so it stops running at all.
 *
 * The cost does not show on memory_limit, because SQLite allocates outside PHP's
 * heap: the serial suite reached 1.2 GB of resident memory by test 540 with only
 * 142 MB on PHP's books, and nothing caps it.
 */
final class FinishedTestCycleCollector implements Extension, FinishedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(Finished $event): void
    {
        gc_collect_cycles();
    }
}
