<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use PDO;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * How a push looks for one workspace (WHIT-1076): the name in its title, the
 * icon beside it and the small badge in the status bar.
 *
 * NAME. The workspace's own site name when it set one, else its name: the one
 * a visitor sees on its public pages ({@see BrandingService::forPublicPage()}),
 * so "AAU IRB · Application waiting for pre-check", never the instance's name.
 *
 * ICON. Its square logo (the public branding asset URL, the same image its
 * public pages show) when it has one; otherwise a tile the web app draws,
 * its initial on its main colour; Whity's own workspaces get the Whity icon.
 *
 * BADGE. Android draws the badge as a one-colour silhouette from the image's
 * transparency alone, so a full-colour square logo would show as a white
 * square. The badge is therefore always drawn: the workspace's initial, or
 * the Whity mark.
 *
 * All paths are on the site the person uses, so a workspace on its own domain
 * shows its own images, and the service worker refuses anything else.
 */
final class PushBranding
{
    /** @var array<int, array{name: string, icon: string, badge: string}> */
    private array $cache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly BrandingService $branding,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo, new BrandingService(new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo))));
    }

    /** @return array{name: string, icon: string, badge: string} */
    public function forWorkspace(int $tenantId): array
    {
        if (isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }
        $tenantName = $this->tenantName($tenantId);
        try {
            $brand = $this->branding->forPublicPage($tenantId, $tenantName);
            $name = trim($brand['name']);
            $logo = $brand['logo_square_url'];
            $color = $brand['main_color'];
        } catch (\Throwable) {
            [$name, $logo, $color] = [$tenantName, null, null];
        }

        return $this->cache[$tenantId] = [
            'name'  => $name,
            'icon'  => is_string($logo) && PushPayload::isLocalPath($logo) ? $logo : self::drawnIcon($name, $color),
            'badge' => self::badge($name),
        ];
    }

    /** The web app's drawn icon: the initial on the colour, or Whity's icon. */
    public static function drawnIcon(string $name, ?string $color): string
    {
        $initial = self::initial($name);
        if ($initial === null || trim($name) === 'Whity') {
            return '/push-icon/icon';
        }
        $query = ['l' => $initial];
        if (is_string($color) && preg_match('/^#([0-9a-fA-F]{6})$/', $color, $m) === 1) {
            $query['c'] = strtolower($m[1]);
        }

        return '/push-icon/icon?' . http_build_query($query);
    }

    public static function badge(string $name): string
    {
        $initial = self::initial($name);

        return $initial === null || trim($name) === 'Whity' ? '/push-icon/badge' : '/push-icon/badge?l=' . $initial;
    }

    /** The first letter when the icon font can draw it (Latin letters and digits). */
    public static function initial(string $name): ?string
    {
        $first = strtoupper(mb_substr(trim($name), 0, 1));

        return preg_match('/^[A-Z0-9]$/', $first) === 1 ? $first : null;
    }

    private function tenantName(int $tenantId): string
    {
        if ($tenantId <= 0) {
            return '';
        }
        try {
            // Tenants is the tenant registry: its primary key IS the tenant id.
            $stmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
            $stmt->execute([':id' => $tenantId]);
            $name = $stmt->fetchColumn();

            return is_string($name) ? $name : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
