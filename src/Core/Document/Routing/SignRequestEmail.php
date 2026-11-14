<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Sdk\Hooks\Events;

/**
 * "Please sign this" (design 3.4 `EmailForms`, `handoff/emails/sign-request`):
 * when a route reaches a `sign` step, each person asked to sign hears it by
 * email from the workspace, with one button that opens their inbox, where they
 * read and sign. Before, only the one-time signing code was ever emailed.
 *
 * Who sent it is the person whose act moved the route there, else the
 * workspace. The email itself signs nothing (EM.1: emails only open the app).
 *
 * It replaces the routing email for sign steps: {@see RoutingNotifications}
 * leaves `email` out for a recipient at a sign step, so a workspace that
 * routes notices by email doesn't send two. Can't be turned off by the person
 * (a signature waits on them); the workspace's email switch still applies.
 * Best-effort: never fails the routing act.
 */
final class SignRequestEmail
{
    public const TYPE = 'document.routing.sign_request';

    public function __construct(
        private readonly PDO $db,
        private readonly ProfileEmailRepository $emails,
        private readonly BrandedEmailSender $sender,
        /** The app's base URL ("https://app.example.com"). */
        private readonly string $appUrl,
    ) {
    }

    public function subscribe(HookManager $hooks): void
    {
        foreach ([Events::DOCUMENT_ROUTED, Events::DOCUMENT_ROUTE_ACTED] as $event) {
            $hooks->listen($event, function (array $data, array $context = []): array {
                try {
                    $this->onRouting($data);
                } catch (\Throwable $e) {
                    error_log('[routing] sign-request email failed: ' . $e->getMessage());
                }

                return $data;
            });
        }
    }

    /**
     * @param array<string, mixed> $payload A routing event's payload.
     * @return int How many emails went out.
     */
    public function onRouting(array $payload): int
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $recipients = $payload['recipients'] ?? null;
        if ($tenantId <= 0 || !is_array($recipients) || $recipients === []) {
            return 0;
        }
        $title = is_string($payload['title'] ?? null) && trim((string) $payload['title']) !== '' ? trim((string) $payload['title']) : null;
        $actor = isset($payload['actor_profile_id']) ? (int) $payload['actor_profile_id'] : null;

        $sent = 0;
        foreach ($recipients as $recipient) {
            if (!is_array($recipient) || !isset($recipient['profile_id'], $recipient['step_id'])) {
                continue;
            }
            if (!self::isSignStep($this->db, $tenantId, (int) $recipient['step_id'])) {
                continue;
            }
            $profileId = (int) $recipient['profile_id'];
            if ($actor !== null && $actor === $profileId) {
                continue; // Nobody is asked by email to sign what they just sent themselves.
            }
            if ($this->send($tenantId, $profileId, $title, $actor)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Whether a route step is a `sign` step. */
    public static function isSignStep(PDO $db, int $tenantId, int $stepId): bool
    {
        $stmt = $db->prepare('SELECT kind FROM document_route_steps WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $stepId]);

        return $stmt->fetchColumn() === RouteStepKind::SIGN;
    }

    private function send(int $tenantId, int $profileId, ?string $title, ?int $actor): bool
    {
        if (!$this->sender->gate()->workspace($tenantId)) {
            return false;
        }
        $email = trim((string) ($this->emails->findPrimaryForProfile($profileId)['email'] ?? ''));
        if ($email === '') {
            return false;
        }
        $lang = $this->sender->language($tenantId);
        $ar = $lang === 'ar';
        $workspace = $this->sender->branding($tenantId)->siteName;
        $from = $actor !== null ? $this->name($actor) : null;
        $who = $from ?? $workspace;
        $doc = $title ?? ($ar ? 'مستند' : 'a document');

        return $this->sender->send(
            $tenantId,
            $email,
            $ar ? $who . ' يطلب توقيعك على ' . $doc : $who . ' asks you to sign ' . $doc,
            new EmailContent(
                heading: $ar ? 'يُرجى التوقيع' : 'Please sign this',
                ctaLabel: $ar ? 'اقرأ ووقّع' : 'Read and sign',
                ctaUrl: rtrim($this->appUrl, '/') . '/admin/inbox',
                footnote: $ar
                    ? 'توقّع بالتأكيد في Whity، ويمكنك رسم توقيعك على شاشة لمس إن أردت. لا يمكن لأحد أن يوقّع عنك.'
                    : 'You sign by confirming in Whity and, if you like, drawing your signature on a touch screen. Nobody else can sign for you.',
                preheader: $ar ? 'يستغرق دقيقة.' : 'It takes a minute.',
                notice: $from !== null
                    ? ($ar ? 'أرسلتها ' . $workspace . ' نيابةً عن ' . $from . '.' : 'Sent by ' . $workspace . ' for ' . $from . '.')
                    : ($ar ? 'أرسلتها ' . $workspace . '.' : 'Sent by ' . $workspace . '.'),
                language: $lang,
                leadLine: $ar
                    ? ['before' => 'أرسل لك ' . $who . ' ', 'strong' => $doc, 'after' => ' لقراءته وتوقيعه.']
                    : ['before' => $who . ' sent you ', 'strong' => $doc, 'after' => ' to read and sign.'],
            ),
        );
    }

    private function name(int $profileId): ?string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
