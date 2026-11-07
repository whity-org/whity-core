<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormBannerApiHandler;
use Whity\Api\FormsApiHandler;
use Whity\Core\Form\FormBanner;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\StorageKey;

/**
 * The heading and picture at the top of a form (WHIT-944, migration 223), on the
 * migrated schema: written by an author, read by the person filling it in on every
 * page that shows the form. Example Bakery's supplier form.
 */
final class FormTitleAndBannerApiRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The smallest PNG: a 1x1 transparent pixel. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private PDO $pdo;
    private FormRepository $forms;
    private FormsApiHandler $formsApi;
    private FormBannerApiHandler $bannerApi;
    private LocalStorageDriver $storage;
    private string $storageRoot;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");

        $this->storageRoot = sys_get_temp_dir() . '/formbanner-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);
        $this->storage = new LocalStorageDriver($this->storageRoot);

        $this->forms = new FormRepository($this->pdo);
        $fields = new FormFieldRepository($this->pdo);
        $this->formsApi = new FormsApiHandler(
            $this->forms,
            $fields,
            new FormRenderer($fields, new PrefillResolver($this->pdo)),
            new FormSubmissionRepository($this->pdo),
            new PublicFormLink('https://records.example.test'),
            $this->storage,
        );
        $this->bannerApi = new FormBannerApiHandler($this->forms, $this->storage);
        $this->formId = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        self::removeTree($this->storageRoot);
    }

    public function testAFormHasNoTitleOrBannerUntilAnAuthorWritesOne(): void
    {
        $form = $this->forms->find(self::TENANT, $this->formId);

        self::assertNotNull($form);
        self::assertNull($form['title']);
        self::assertNull($form['banner']);
    }

    public function testTheTitleIsStoredInBothLanguagesApartFromTheName(): void
    {
        $response = $this->patch(['title' => ['en' => 'Sell flour to Example Bakery', 'ar' => 'بِع الطحين لمخبز المثال']]);

        self::assertSame(200, $response->getStatusCode());
        $data = self::data($response);
        self::assertEquals(['en' => 'Sell flour to Example Bakery', 'ar' => 'بِع الطحين لمخبز المثال'], $data['title']);
        self::assertSame(['en' => 'Supplier request'], $data['name']);

        self::assertSame(200, $this->patch(['title' => null])->getStatusCode());
        self::assertNull($this->stored()['title']);
    }

    public function testATitleLongerThanAHeadingIsRefused(): void
    {
        self::assertSame(422, $this->patch(['title' => ['en' => str_repeat('a', 201)]])->getStatusCode());
    }

    public function testADrawnPatternIsStoredAsItsStyleAndSeed(): void
    {
        $response = $this->patch(['banner' => ['kind' => 'pattern', 'style' => 'waves', 'seed' => 4821]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['kind' => 'pattern', 'style' => 'waves', 'seed' => 4821], self::data($response)['banner']);

        self::assertSame(200, $this->patch(['banner' => null])->getStatusCode());
        self::assertNull($this->stored()['banner']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedBanners(): iterable
    {
        yield 'a string' => ['waves'];
        yield 'an unknown kind' => [['kind' => 'video']];
        yield 'an unknown style' => [['kind' => 'pattern', 'style' => 'plaid', 'seed' => 1]];
        yield 'a seed that is not a number' => [['kind' => 'pattern', 'style' => 'dots', 'seed' => '7']];
        yield 'a negative seed' => [['kind' => 'pattern', 'style' => 'dots', 'seed' => -1]];
        // An image is set by uploading it, never by naming a file.
        yield 'an image with nothing uploaded' => [['kind' => 'image', 'name' => 'form-banner-1-0123456789abcdef.png']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBanners')]
    public function testAMalformedBannerIsRefusedWithoutWriting(mixed $banner): void
    {
        self::assertSame(422, $this->patch(['banner' => $banner])->getStatusCode());
        self::assertNull($this->stored()['banner']);
    }

    public function testAnUploadedPictureIsServedFromTheWorkspacesBrandingAssets(): void
    {
        $response = $this->upload((string) base64_decode(self::PNG_BASE64, true));

        self::assertSame(200, $response->getStatusCode());
        $banner = self::data($response)['banner'];
        self::assertSame('image', $banner['kind']);
        self::assertMatchesRegularExpression('#^/api/v1/branding/asset/1/form-banner-\d+-[0-9a-f]{16}\.png$#', $banner['image_url']);
        self::assertTrue($this->storage->exists(self::keyOf($banner['image_url'])));

        // Saving the form's settings again sends the picture back as `image`, and keeps it.
        self::assertSame(200, $this->patch(['banner' => ['kind' => 'image'], 'title' => ['en' => 'Sell us flour']])->getStatusCode());
        self::assertSame($banner, $this->stored()['banner']);
    }

    public function testChoosingAPatternDeletesTheUploadedPicture(): void
    {
        $banner = self::data($this->upload((string) base64_decode(self::PNG_BASE64, true)))['banner'];
        $key = self::keyOf($banner['image_url']);

        $this->patch(['banner' => ['kind' => 'pattern', 'style' => 'dots', 'seed' => 7]]);

        self::assertFalse($this->storage->exists($key));
    }

    public function testAPictureThatIsNotAPngJpegOrWebpIsRefused(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        self::assertSame(422, $this->upload($svg)->getStatusCode());
        self::assertSame(422, $this->upload('')->getStatusCode());
        self::assertNull($this->stored()['banner']);
    }

    public function testThePersonFillingItInReadsTheTitleAndBanner(): void
    {
        $this->patch([
            'title' => ['en' => 'Sell flour to Example Bakery'],
            'banner' => ['kind' => 'pattern', 'style' => 'mesh', 'seed' => 12],
        ]);

        $public = PublicFormView::form((array) $this->forms->find(self::TENANT, $this->formId), []);

        self::assertSame(['en' => 'Sell flour to Example Bakery'], $public['title']);
        self::assertSame(['kind' => 'pattern', 'style' => 'mesh', 'seed' => 12], $public['banner']);
    }

    public function testAStoredBannerThisVersionCannotDrawReadsAsNone(): void
    {
        self::assertNull(FormBanner::fromStorage('{"kind":"pattern","style":"plaid","seed":1}'));
        self::assertNull(FormBanner::fromStorage('{"kind":"image","name":"../../etc/passwd"}'));
        self::assertNull(FormBanner::fromStorage('not json'));
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $form = $this->forms->find(self::TENANT, $this->formId);
        self::assertNotNull($form);

        return $form;
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): Response
    {
        return $this->formsApi->update(
            new Request('PATCH', '/api/forms/' . $this->formId, [], json_encode($body, JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId],
        );
    }

    private function upload(string $bytes): Response
    {
        $boundary = '----WhityFormBanner' . bin2hex(random_bytes(6));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"banner.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . $bytes
            . "\r\n--{$boundary}--\r\n";

        return $this->bannerApi->upload(
            new Request('POST', '/x', ['Content-Type' => "multipart/form-data; boundary={$boundary}"], $body),
            ['id' => (string) $this->formId],
        );
    }

    private static function keyOf(string $imageUrl): string
    {
        return StorageKey::build(self::TENANT, 'branding', basename($imageUrl));
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded['data'];
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
