<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Form\FormDecisionEmail;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;

/**
 * E2 `form-decision` (WHIT-1034): the submitter hears the decision once, with
 * the final document's PDF when the route made one.
 */
final class FormDecisionEmailTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    /** @var list<OutgoingEmail> */
    private array $sent = [];
    private FormDecisionEmail $email;
    private DocumentArtifactStore $store;
    private string $dir;
    private int $profileId;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example University', 'example-university')");
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Ahmad Khalil', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$this->profileId}, 'ahmad@example.test', true, true, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO forms (tenant_id, form_key, name, status, version, created_at, updated_at)
             VALUES (1, 'ethics', '{\"en\":\"ethics application\"}', 'published', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->formId = (int) $this->pdo->lastInsertId();

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal('site_name', 'Example University');
        $mailer = new class (function (OutgoingEmail $e): void {
            $this->sent[] = $e;
        }) implements Mailer, ThreadedMailer {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->sink)(new OutgoingEmail($toEmail, $subject, $textBody, $htmlBody));
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                ($this->sink)($email);
            }
        };
        $sender = new BrandedEmailSender($mailer, new EmailLayout(), $settings);
        $sender->useGate(new EmailGate($settings, $this->pdo));
        $this->dir = sys_get_temp_dir() . '/whity-form-decision-' . bin2hex(random_bytes(4));
        $this->store = new DocumentArtifactStore(new LocalStorageDriver($this->dir));
        $this->email = new FormDecisionEmail($this->pdo, new ProfileEmailRepository($this->pdo), $sender, $settings, 'https://app.example.test', $this->store);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testAnApprovalWithAFinalDocumentCarriesItsPdf(): void
    {
        $submission = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, $this->profileId, null, [], 'REC-EU 14/3/2026-27');
        $this->pdo->exec("INSERT INTO documents (tenant_id, template_name, title, created_by, created_at) VALUES (1, 'Approval', 'Approval', NULL, CURRENT_TIMESTAMP)");
        $documentId = (int) $this->pdo->lastInsertId();
        $stored = $this->store->put(self::TENANT, $documentId, '%PDF-1.7 approval', 'application/pdf');
        $artifactId = (new DocumentArtifactRepository($this->pdo))->create(self::TENANT, [
            'document_id' => $documentId,
            'storage_key' => $stored->storageKey,
            'content_type' => 'application/pdf',
            'byte_size' => $stored->byteSize,
            'checksum_sha256' => $stored->checksumSha256,
        ]);

        self::assertTrue($this->email->onFinalised([
            'tenant_id' => self::TENANT, 'document_id' => $documentId, 'artifact_id' => $artifactId,
            'route_id' => 0, 'subject_type' => FormSubmissionRouteSubject::TYPE, 'subject_id' => $submission,
        ]));

        $mail = $this->sent[0];
        self::assertSame('ahmad@example.test', $mail->to);
        self::assertSame('Approved: ethics application REC-EU 14/3/2026-27', $mail->subject);
        self::assertStringContainsString('Example University decided on your ethics application on ', $mail->textBody);
        self::assertStringContainsString('Conditions: None', $mail->textBody);
        self::assertStringContainsString('Download the approval: https://app.example.test/admin/x/my-form-submissions', $mail->textBody);
        self::assertCount(1, $mail->attachments);
        self::assertSame('approval.pdf', $mail->attachments[0]->filename);
        self::assertSame('%PDF-1.7 approval', $mail->attachments[0]->content);
    }

    public function testANotApprovedRouteSendsOnCompletionWithNoFile(): void
    {
        $submission = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, $this->profileId, null, []);

        self::assertTrue($this->email->onCompleted([
            'tenant_id' => self::TENANT, 'route_id' => 0, 'outcome' => 'rejected',
            'subject_type' => FormSubmissionRouteSubject::TYPE, 'subject_id' => $submission,
        ]));

        $mail = $this->sent[0];
        self::assertSame('Not approved: ethics application', $mail->subject);
        self::assertStringContainsString('Decision: Not approved', $mail->textBody);
        self::assertStringNotContainsString('Conditions', $mail->textBody);
        self::assertSame([], $mail->attachments);
    }

    public function testAPublicSubmissionAndOtherSubjectsGetNothing(): void
    {
        $public = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, null, null, []);

        self::assertFalse($this->email->onCompleted(['tenant_id' => self::TENANT, 'outcome' => 'approved', 'subject_type' => FormSubmissionRouteSubject::TYPE, 'subject_id' => $public]));
        self::assertFalse($this->email->onCompleted(['tenant_id' => self::TENANT, 'outcome' => 'approved', 'subject_type' => 'document', 'subject_id' => 1]));
        self::assertSame([], $this->sent);
    }
}
