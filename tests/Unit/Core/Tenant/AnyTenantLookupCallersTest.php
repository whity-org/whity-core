<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\TranslationRepositoryInterface;

/**
 * Lookups that read across every workspace, and who is allowed to call them
 * (#1144, Phase 2).
 *
 * Each of these is a `@tenant-guard-ignore` exemption whose safety rested on a
 * sentence in a docblock: "used only by …", "must not be called from …". The
 * guard cannot hold a caller to a sentence, so a new caller would get an
 * any-workspace read with no gate and no CI signal. This holds each to its
 * named callers. Adding one is fine; it means adding it here, where somebody
 * reads why the existing ones are safe.
 */
final class AnyTenantLookupCallersTest extends TestCase
{
    /**
     * Every workspace that claims an email domain. Safe for the domain-policy
     * service, which decides sign-up membership before any workspace is
     * chosen; a request handler calling it would list other workspaces.
     */
    public function testOnlyTheDomainPolicyServiceListsEveryWorkspaceClaimingADomain(): void
    {
        self::assertSame(
            ['src/Core/Identity/TenantEmailDomainPolicyService.php'],
            $this->callersOf('/->findTenantsByDomain\(/')
        );
    }

    /**
     * A person by id in ANY workspace, asked for with a literal 0. Both callers
     * look the person up unscoped to tell a missing person from another
     * workspace's, and then compare `tenant_id` with the acting tenant
     * themselves (CrossTenantReferenceException, a generic 404).
     */
    public function testOnlyTheRelationResolverAndHandlerReadAPersonInAnyWorkspace(): void
    {
        self::assertSame(
            ['src/Api/RelationsApiHandler.php', 'src/Core/Relations/RelationResolver.php'],
            $this->callersOf('/persons->findById\([^;]*,\s*0\s*\)/')
        );
    }

    /**
     * The translations interface is reachable from anywhere the container is,
     * so it carries no any-workspace read: lookups by id go through
     * findVisibleTo(), scoped in its statement.
     */
    public function testTheTranslationsInterfaceHasNoUnscopedLookupById(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(TranslationRepositoryInterface::class))->getMethods()
        );

        self::assertContains('findVisibleTo', $methods);
        self::assertNotContains('findById', $methods);
    }

    /** @return list<string> files under src/, plugins/ and sdk/ whose code matches */
    private function callersOf(string $pattern): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (['src', 'plugins', 'sdk/src'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (str_ends_with($path, '.php') && preg_match($pattern, (string) file_get_contents($path)) === 1) {
                    $found[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
                }
            }
        }
        sort($found);

        return $found;
    }
}
