<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Closure;
use PDO;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\Email\EmailChannel;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Builds Messages the same way in every entry point (ADR 0016): the web
 * request, the CLI kernel and the queue worker. Core's two channels are
 * registered here; plugin channels join the same registry when plugins load.
 */
final class CoreMessaging
{
    private function __construct()
    {
    }

    /**
     * @param Closure(string, array<string, mixed>, array<string, mixed>): mixed $enqueue
     */
    public static function build(
        PDO $pdo,
        MessageChannelRegistry $channels,
        ThreadedMailer $mailer,
        EncryptedSecretStore $secrets,
        Closure $enqueue,
        ?MeterService $meters = null,
        ?string $appUrl = null,
    ): MessagingService {
        $settings = new MessagingSettings($pdo, $secrets);

        if (!$channels->has(EmailChannel::KEY)) {
            $channels->registerCore(new EmailChannel(
                $mailer,
                $settings,
                static function (int $tenantId) use ($pdo): ?string {
                    $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :tid');
                    $stmt->execute([':tid' => $tenantId]);
                    $name = $stmt->fetchColumn();

                    return is_string($name) ? $name : null;
                },
                self::messageIdHost($appUrl),
            ));
        }
        if (!$channels->has(WebChatChannel::KEY)) {
            $channels->registerCore(new WebChatChannel($settings));
        }

        return new MessagingService(
            $pdo,
            new ConversationRepository($pdo),
            new MessageRepository($pdo),
            $channels,
            $enqueue,
            $meters,
        );
    }

    /** The host part of our Message-IDs: the instance's own host, or a stable fallback. */
    public static function messageIdHost(?string $appUrl): string
    {
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1 ? strtolower($host) : 'whity.local';
    }
}
