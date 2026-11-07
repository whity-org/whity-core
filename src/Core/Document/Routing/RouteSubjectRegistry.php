<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;
use Whity\Core\Container\HostWiredService;

/**
 * The route subject types this install knows (WHIT-918, #1722).
 *
 * Core registers its own types at boot. A route whose `subject_type` is not
 * registered here (a plugin's, with the plugin disabled) still reads, because the
 * trail and the recipient rows stand on their own; it simply has no provider to
 * answer for it.
 *
 * {@see HostWiredService}: the host fills it at boot (public/index.php and
 * BaseCommand::setupKernel()), so an improvised empty one must not be handed out.
 */
final class RouteSubjectRegistry implements HostWiredService
{
    /** @var array<string, RouteSubjectProviderInterface> */
    private array $providers = [];

    public function register(RouteSubjectProviderInterface $provider): void
    {
        $type = $provider->type();
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/', $type) !== 1 || strlen($type) > 64) {
            throw new InvalidArgumentException("'{$type}' is not a valid route subject type.");
        }
        if (isset($this->providers[$type])) {
            throw new InvalidArgumentException("Route subject type '{$type}' is already registered.");
        }
        $this->providers[$type] = $provider;
    }

    public function get(string $type): ?RouteSubjectProviderInterface
    {
        return $this->providers[$type] ?? null;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->providers);
    }
}
