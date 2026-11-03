<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormsNavConditions;
use Whity\Core\RBAC\CorePermissions;

/**
 * Nobody's sidebar changes until a workspace opens a form to its members.
 */
final class FormsNavConditionsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const MEMBER = 11;
    private const FORMER_MEMBER = 12;
    private const SUBMITTER = 14;
    private const READER = 15;

    private PDO $pdo;
    private FormRepository $forms;
    private FormsNavConditions $conditions;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (11, 'Sami', 'x', false, 0, 0, NOW(), NOW()), (12, 'Lina', 'x', false, 0, 0, NOW(), NOW()),
                   (14, 'Rana', 'x', false, 0, 0, NOW(), NOW()), (15, 'Omar', 'x', false, 0, 0, NOW(), NOW())
        ");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (100, 'baker', '', 1, NOW())");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (11, 1, 100, NULL, true, 'active', NOW()), (12, 1, 100, NULL, true, 'inactive', NOW()),
                   (14, 1, 100, NULL, true, 'active', NOW()), (15, 1, 100, NULL, true, 'active', NOW())
        ");

        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $profile, string $permission): bool => match ($permission) {
                CorePermissions::FORMS_SUBMIT => $profile === self::SUBMITTER || $profile === self::READER,
                CorePermissions::FORMS_READ => $profile === self::READER,
                default => false,
            }
        );

        $this->forms = new FormRepository($this->pdo);
        $this->conditions = new FormsNavConditions($this->pdo, $roles);
    }

    private function openForm(int $tenantId = self::TENANT, bool $publish = true): int
    {
        $id = $this->forms->create($tenantId, 'f' . bin2hex(random_bytes(3)), ['en' => 'Supplier request'], null, null, null);
        if ($publish) {
            $this->forms->transition($tenantId, $id, 'draft', 'published');
        }
        $this->forms->setOpenToMembers($tenantId, $id, true, null);

        return $id;
    }

    public function testNoOpenFormMeansNoChangeForAMember(): void
    {
        self::assertFalse($this->conditions->hasFormsOpenToThem(self::MEMBER, self::TENANT));
        self::assertFalse($this->conditions->hasOwnSubmissionsToSee(self::MEMBER, self::TENANT));
    }

    public function testAnOpenFormShowsBothToAMember(): void
    {
        $this->openForm();

        self::assertTrue($this->conditions->hasFormsOpenToThem(self::MEMBER, self::TENANT));
        self::assertTrue($this->conditions->hasOwnSubmissionsToSee(self::MEMBER, self::TENANT));
    }

    public function testAnOpenFormThatIsNotPublishedDoesNotCount(): void
    {
        $this->openForm(self::TENANT, false);

        self::assertFalse($this->conditions->hasFormsOpenToThem(self::MEMBER, self::TENANT));
    }

    public function testAFormOpenInAnotherWorkspaceDoesNotCount(): void
    {
        $this->openForm(self::OTHER_TENANT);

        self::assertFalse($this->conditions->hasFormsOpenToThem(self::MEMBER, self::TENANT));
    }

    public function testAFormerMemberGetsNeitherFromAnOpenForm(): void
    {
        $this->openForm();

        self::assertFalse($this->conditions->hasFormsOpenToThem(self::FORMER_MEMBER, self::TENANT));
        self::assertFalse($this->conditions->hasOwnSubmissionsToSee(self::FORMER_MEMBER, self::TENANT));
    }

    public function testSomebodyWhoAlreadySentOneKeepsMySubmissions(): void
    {
        $formId = $this->openForm();
        (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $formId, 1, self::FORMER_MEMBER, null, []);

        self::assertTrue($this->conditions->hasOwnSubmissionsToSee(self::FORMER_MEMBER, self::TENANT));
    }

    public function testAHolderOfSubmitKeepsMySubmissionsWithNoOpenForm(): void
    {
        self::assertTrue($this->conditions->hasOwnSubmissionsToSee(self::SUBMITTER, self::TENANT));
    }

    public function testAReaderAlreadyHasTheCatalogueSoGetsNoSecondEntry(): void
    {
        $this->openForm();

        self::assertFalse($this->conditions->hasFormsOpenToThem(self::READER, self::TENANT));
    }
}
