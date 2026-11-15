<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use Whity\Sdk\Notification\NotificationMessage;

/**
 * What a push notification says, and where tapping it goes (WHIT-1074).
 *
 * The service worker shows exactly this: a title, one short line, the page to
 * open, a tag, and the language and direction to draw it in. Nothing else of
 * the notification travels: no ids beyond the page path, no amounts, no data
 * blob. Notifications only open the app (M.44): there are no action buttons.
 *
 * TITLE. "<workspace> · <what>", so a person in two workspaces knows which
 * one is asking (TmPush, M.17). A notification with no words of its own (some
 * kinds carry only data) says "Something is waiting for you".
 *
 * LINK. Always a path on the same site, never a full URL: the worker opens it
 * on the origin it serves, so a workspace on its own domain opens its own
 * domain, and a crafted notification can't send anyone elsewhere.
 */
final class PushPayload
{
    /** Kept short: lock screens show two or three lines at most. */
    public const MAX_BODY = 180;
    public const MAX_TITLE = 120;

    public const FALLBACK_PATH = '/admin/inbox';

    /**
     * @param array{icon?: string|null, badge?: string|null} $images
     * @return array{title: string, body: string, url: string, tag: string, lang: string, dir: string, icon?: string, badge?: string}
     */
    public static function build(NotificationMessage $message, string $language, string $workspaceName, int $notificationId, array $images = []): array
    {
        $ar = $language === 'ar';
        $data = $message->data;

        // Plugin automations hand both languages over in data.
        $subject = $ar && is_string($data['subjectAr'] ?? null) && trim($data['subjectAr']) !== '' ? $data['subjectAr'] : $message->subject;
        $body = $ar && is_string($data['bodyAr'] ?? null) && trim($data['bodyAr']) !== '' ? $data['bodyAr'] : $message->body;

        $what = self::oneLine($subject);
        if ($what === '') {
            $what = $ar ? 'شيء بانتظارك' : 'Something is waiting for you';
        }
        $line = self::oneLine($body);
        if ($line === $what) {
            $line = '';
        }

        $title = trim($workspaceName) !== '' ? trim($workspaceName) . ' · ' . $what : $what;
        $payload = [
            'title' => self::cut($title, self::MAX_TITLE),
            'body'  => self::cut($line, self::MAX_BODY),
            'url'   => self::link($message->type, $data),
            // A caller may group its pushes under one tag (a chat: each new
            // one replaces the last on the device); else one per notification.
            'tag'   => is_string($data['push_tag'] ?? null) && preg_match('/^[a-z0-9-]{1,40}$/', $data['push_tag']) === 1
                ? $data['push_tag']
                : 'n' . $notificationId,
            'lang'  => $ar ? 'ar' : 'en',
            'dir'   => $ar ? 'rtl' : 'ltr',
        ];
        foreach (['icon', 'badge'] as $image) {
            if (is_string($images[$image] ?? null) && $images[$image] !== '') {
                $payload[$image] = $images[$image];
            }
        }

        return $payload;
    }

    /**
     * The one push that stands for several held back by the rate limit.
     *
     * @param array{icon?: string|null, badge?: string|null} $images
     * @return array{title: string, body: string, url: string, tag: string, lang: string, dir: string, icon?: string, badge?: string}
     */
    public static function digest(int $count, string $language, string $workspaceName, int $tenantId, array $images = []): array
    {
        $ar = $language === 'ar';
        $what = $ar
            ? ($count === 1 ? 'أمر آخر بانتظارك' : $count . ' أمور أخرى بانتظارك')
            : ($count === 1 ? '1 more thing is waiting for you' : $count . ' more things are waiting for you');
        $payload = [
            'title' => self::cut(trim($workspaceName) !== '' ? trim($workspaceName) . ' · ' . $what : $what, self::MAX_TITLE),
            'body'  => '',
            'url'   => self::FALLBACK_PATH,
            'tag'   => 'digest' . $tenantId,
            'lang'  => $ar ? 'ar' : 'en',
            'dir'   => $ar ? 'rtl' : 'ltr',
        ];
        foreach (['icon', 'badge'] as $image) {
            if (is_string($images[$image] ?? null) && $images[$image] !== '') {
                $payload[$image] = $images[$image];
            }
        }

        return $payload;
    }

    /**
     * The page a notification opens: a path the caller gave, else the record
     * its data names, else the inbox.
     *
     * @param array<string, mixed> $data
     */
    public static function link(string $type, array $data): string
    {
        $given = $data['url'] ?? $data['path'] ?? null;
        if (is_string($given) && self::isLocalPath($given)) {
            return $given;
        }
        if (isset($data['document_id']) && is_numeric($data['document_id']) && (int) $data['document_id'] > 0) {
            return '/admin/document-library/' . (int) $data['document_id'];
        }
        if (str_starts_with($type, 'error') && isset($data['group_id'])) {
            return '/admin/errors';
        }

        return self::FALLBACK_PATH;
    }

    /** A path on this site: starts with one slash, no scheme, no backslashes. */
    public static function isLocalPath(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) === 1 && strlen($path) <= 512;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
