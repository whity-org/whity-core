<?php

declare(strict_types=1);

namespace Tests\Plugins;

use MyCourses\Migrations\CreateMyCoursesEnrolmentsTable;
use MyCourses\MyCoursesPlugin;
use PDO;
use Whity\Sdk\Testing\CustomerIsolationTestCase;

require_once dirname(__DIR__, 2) . '/plugins/MyCourses/Migrations/CreateMyCoursesEnrolmentsTable.php';
require_once dirname(__DIR__, 2) . '/plugins/MyCourses/MyCoursesPlugin.php';

/**
 * The reference customer-area plugin keeps each learner to their own
 * enrolments (WHIT-1426, design section 41 CA3), through the SDK's
 * {@see CustomerIsolationTestCase}: the same test a plugin author writes.
 */
final class MyCoursesCustomerIsolationTest extends CustomerIsolationTestCase
{
    private PDO $pdo;
    private MyCoursesPlugin $plugin;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // SQLite has no SERIAL; the migration's table shape otherwise.
        $this->pdo->exec('CREATE TABLE mycourses_enrolments (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, contact_id INTEGER NOT NULL, course VARCHAR(200) NOT NULL, lessons INTEGER NOT NULL DEFAULT 8, lessons_done INTEGER NOT NULL DEFAULT 0, created_at TIMESTAMP)');
        $this->pdo->exec("INSERT INTO mycourses_enrolments (id, tenant_id, contact_id, course, lessons, lessons_done) VALUES
            (71, 1, 61, 'Cake decorating basics', 8, 3),
            (72, 1, 64, 'Ibrahim private course', 4, 1),
            (73, 2, 61, 'Another workspace course', 4, 0)");
        $this->plugin = new MyCoursesPlugin($this->pdo);
    }

    public function testALearnerOpensOnlyTheirOwnEnrolment(): void
    {
        $ahmad = $this->customer(31, contactIds: [61]);
        $one = [$this->plugin, 'one'];

        $this->assertYours($one, $this->customerRequest('GET', '/api/mycourses/mine/71', $ahmad), ['id' => '71']);
        $this->assertNotYours($one, $this->customerRequest('GET', '/api/mycourses/mine/72', $ahmad), ['id' => '72'], ['Ibrahim']);
        // The same contact id in another workspace is not theirs here.
        $this->assertNotYours($one, $this->customerRequest('GET', '/api/mycourses/mine/73', $ahmad), ['id' => '73'], ['Another workspace']);
        $this->assertNeedsACustomer($one, $this->customerRequest('GET', '/api/mycourses/mine/71', $ahmad), ['id' => '71']);
    }

    public function testTheListAndCountHoldOnlyTheirs(): void
    {
        $ahmad = $this->customer(31, contactIds: [61]);

        $list = ($this->plugin->mine(...))($this->customerRequest('GET', '/api/mycourses/mine', $ahmad));
        self::assertStringContainsString('Cake decorating basics', $list->getBody());
        self::assertStringNotContainsString('Ibrahim', $list->getBody());
        self::assertStringNotContainsString('Another workspace', $list->getBody());
        self::assertSame(['count' => 1], json_decode(($this->plugin->count(...))($this->customerRequest('GET', '/api/mycourses/mine/count', $ahmad))->getBody(), true));
    }

    public function testALearnerCannotMoveAnotherLearnersProgress(): void
    {
        $ahmad = $this->customer(31, contactIds: [61]);
        $progress = [$this->plugin, 'progress'];

        $this->assertNotYours($progress, $this->customerRequest('POST', '/api/mycourses/mine/72/progress', $ahmad, []), ['id' => '72']);
        self::assertSame(1, $this->done(72));

        $this->assertYours($progress, $this->customerRequest('POST', '/api/mycourses/mine/71/progress', $ahmad, []), ['id' => '71']);
        self::assertSame(4, $this->done(71));
    }

    private function done(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT lessons_done FROM mycourses_enrolments WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    public function testEveryCustomerScreenUsesOnlyCustomerRoutes(): void
    {
        $customerPaths = [];
        foreach ($this->plugin->getRoutes() as $route) {
            if (($route['customer'] ?? false) === true) {
                $customerPaths[] = $route['path'];
                self::assertContains($route['requiredPermission'] ?? null, $this->plugin->getCustomerPermissions());
            }
        }
        foreach ($this->plugin->getCustomerScreens() as $screen) {
            $paths = [];
            array_walk_recursive($screen, static function (mixed $v) use (&$paths): void {
                if (is_string($v) && str_starts_with($v, '/api/')) {
                    $paths[] = $v;
                }
            });
            foreach ($paths as $path) {
                self::assertContains($path, $customerPaths, "{$screen['id']} uses {$path}");
            }
        }
        self::assertTrue(class_exists(CreateMyCoursesEnrolmentsTable::class));
    }
}
