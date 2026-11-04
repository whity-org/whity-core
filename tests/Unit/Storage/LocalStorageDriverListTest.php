<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\StorageException;

/** LocalStorageDriver::list() (WHIT-886): recursive, sorted, and confined to its root. */
final class LocalStorageDriverListTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whity-list-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testListsEveryKeyUnderThePrefixRecursivelyAndInOrder(): void
    {
        $driver = new LocalStorageDriver($this->root);
        $driver->put('tenants/1/reports/b.txt', 'b');
        $driver->put('tenants/1/reports/a/deep.txt', 'd');
        $driver->put('tenants/1/reports/a.txt', 'a');
        $driver->put('tenants/1/ledger/secret.txt', 'x');
        $driver->put('tenants/2/reports/other.txt', 'o');

        $this->assertSame(
            [
                'tenants/1/reports/a.txt',
                'tenants/1/reports/a/deep.txt',
                'tenants/1/reports/b.txt',
            ],
            $driver->list('tenants/1/reports/'),
        );
    }

    public function testAPrefixThatNamesNothingIsEmptyNotAnError(): void
    {
        $driver = new LocalStorageDriver($this->root);

        $this->assertSame([], $driver->list('tenants/9/nothing/'));
    }

    public function testAPrefixMatchesOnlyWhatItNamesEvenWhenItIsAPartialName(): void
    {
        $driver = new LocalStorageDriver($this->root);
        $driver->put('tenants/1/reports/a.txt', 'a');
        $driver->put('tenants/1/reportsx/b.txt', 'b');

        $this->assertSame(['tenants/1/reports/a.txt'], $driver->list('tenants/1/reports/'));
    }

    public function testRefusesATraversingPrefix(): void
    {
        $driver = new LocalStorageDriver($this->root);

        $this->expectException(StorageException::class);

        $driver->list('tenants/1/../2/');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
