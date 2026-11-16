<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use Closure;
use DateTimeImmutable;
use PDO;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\i18n\LocalizedText;

/**
 * Blocks in customer chats (design 3.5, UC.5, UC.10, WHIT-1106): booking
 * times, a form to fill and a payment link, sent from the + beside the box.
 *
 * The customer's channel carries words, so what reaches them is the person's
 * own words with the link written under them; the team sees the block. Links
 * are built here from what the workspace has (its booking page, a form's
 * public link), never taken from the browser, except a payment link, which
 * Whity Pay made a moment before and which must be an https address.
 */
final class CustomerBlocks
{
    private const MAX_TIMES = 6;

    /**
     * @param Closure(int): array<string, string> $settings the workspace's effective settings
     */
    public function __construct(
        private readonly PDO $db,
        private readonly PublicFormLink $forms,
        private readonly string $publicBase,
        private readonly Closure $settings,
    ) {
    }

    /**
     * What the + menu can offer this workspace's customer chats.
     *
     * @return array{booking_page: bool, time_zone: string, services: list<array{id: int, name: string, minutes: int}>, forms: list<array{id: int, title: string}>}
     */
    public function options(int $tenantId): array
    {
        $calendar = CalendarSettings::fromSettings(($this->settings)($tenantId));
        $services = [];
        if ($calendar->bookingPageEnabled && $this->bookingUrl($tenantId) !== null) {
            $stmt = $this->db->prepare('SELECT id, name, duration_minutes FROM calendar_services WHERE tenant_id = :tid AND active = TRUE ORDER BY id LIMIT 100');
            $stmt->execute([':tid' => $tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $services[] = ['id' => (int) $r['id'], 'name' => self::name($r['name']), 'minutes' => (int) $r['duration_minutes']];
            }
        }
        $stmt = $this->db->prepare("SELECT id, name, title FROM forms WHERE tenant_id = :tid AND public_enabled = TRUE AND public_slug IS NOT NULL AND status = 'published' ORDER BY name LIMIT 100");
        $stmt->execute([':tid' => $tenantId]);
        $forms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $forms[] = ['id' => (int) $r['id'], 'title' => self::name($r['title'] ?? null) !== '' ? self::name($r['title']) : (string) $r['name']];
        }

        // Times are picked and shown in the calendar's zone, the one the customer's text uses.
        return ['booking_page' => $services !== [], 'time_zone' => $calendar->zone->getName(), 'services' => $services, 'forms' => $forms];
    }

    /**
     * The words to send (the person's own, with the link under them) and the
     * block the team sees.
     *
     * @param array<string, mixed> $input
     * @return array{text: string, block: array<string, mixed>}
     *
     * @throws ChatException
     */
    public function build(int $tenantId, array $input): array
    {
        $words = trim(mb_substr((string) (is_scalar($input['text'] ?? null) ? $input['text'] : ''), 0, 1000));

        return match ($input['type'] ?? null) {
            'booking_times' => $this->bookingTimes($tenantId, $input, $words),
            'form' => $this->form($tenantId, $input, $words),
            'payment' => self::payment($input, $words),
            default => throw new ChatException('That is not something Chat sends to customers.'),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array{text: string, block: array<string, mixed>}
     */
    private function bookingTimes(int $tenantId, array $input, string $words): array
    {
        $calendar = CalendarSettings::fromSettings(($this->settings)($tenantId));
        $url = $this->bookingUrl($tenantId);
        if (!$calendar->bookingPageEnabled || $url === null) {
            throw new ChatException('Turn on the booking page in Calendar settings to send booking times.');
        }
        $stmt = $this->db->prepare('SELECT id, name, duration_minutes FROM calendar_services WHERE tenant_id = :tid AND id = :id AND active = TRUE');
        $stmt->execute([':tid' => $tenantId, ':id' => (int) ($input['service_id'] ?? 0)]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($service)) {
            throw new ChatException('Choose a service the booking page offers.');
        }
        $times = [];
        foreach (is_array($input['times'] ?? null) ? $input['times'] : [] as $t) {
            $ts = is_string($t) ? strtotime($t) : false;
            if ($ts !== false && $ts > time()) {
                $times[$ts] = gmdate('Y-m-d\TH:i:s\Z', $ts);
            }
        }
        ksort($times);
        $times = array_values(array_slice($times, 0, self::MAX_TIMES, true));
        if ($times === []) {
            throw new ChatException('Add at least one time from now on.');
        }
        $name = self::name($service['name']);
        $local = array_map(
            static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone($calendar->zone)->format('D j M, H:i'),
            $times,
        );
        $text = ($words !== '' ? $words . "\n\n" : '') . $name . ': ' . implode(' · ', $local) . "\n" . $url;

        return ['text' => $text, 'block' => [
            'type' => 'booking_times',
            'service' => ['id' => (int) $service['id'], 'name' => $name, 'minutes' => (int) $service['duration_minutes']],
            'times' => $times,
            'time_zone' => $calendar->zone->getName(),
            'url' => $url,
        ]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{text: string, block: array<string, mixed>}
     */
    private function form(int $tenantId, array $input, string $words): array
    {
        $stmt = $this->db->prepare("SELECT id, name, title, public_slug FROM forms WHERE tenant_id = :tid AND id = :id AND public_enabled = TRUE AND status = 'published'");
        $stmt->execute([':tid' => $tenantId, ':id' => (int) ($input['form_id'] ?? 0)]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        $url = is_array($form) ? $this->forms->publicUrl(is_string($form['public_slug']) ? $form['public_slug'] : null) : null;
        if (!is_array($form) || $url === null) {
            throw new ChatException('Choose a form that has a public link.');
        }
        $title = self::name($form['title'] ?? null) !== '' ? self::name($form['title']) : (string) $form['name'];

        return ['text' => ($words !== '' ? $words . "\n\n" : '') . $title . "\n" . $url, 'block' => [
            'type' => 'form',
            'form' => ['id' => (int) $form['id'], 'title' => $title],
            'url' => $url,
        ]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{text: string, block: array<string, mixed>}
     */
    private static function payment(array $input, string $words): array
    {
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        $currency = is_string($input['currency'] ?? null) ? strtoupper(trim($input['currency'])) : '';
        $amount = is_string($input['amount'] ?? null) ? trim($input['amount']) : '';
        $description = trim(mb_substr((string) (is_scalar($input['description'] ?? null) ? $input['description'] : ''), 0, 200));
        if (preg_match('#^https://[^\s]+$#', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ChatException('The payment link must be an https address from Whity Pay.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || preg_match('/^\d+(\.\d{1,3})?$/', $amount) !== 1 || $description === '') {
            throw new ChatException('Say what the payment is for, and its amount and currency.');
        }
        $line = $description . ': ' . $currency . ' ' . $amount;

        return ['text' => ($words !== '' ? $words . "\n\n" : '') . $line . "\n" . $url, 'block' => [
            'type' => 'payment',
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'url' => $url,
            'reference' => is_string($input['reference'] ?? null) ? mb_substr($input['reference'], 0, 80) : null,
        ]];
    }

    private function bookingUrl(int $tenantId): ?string
    {
        if ($this->publicBase === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT slug FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $slug = $stmt->fetchColumn();

        return is_string($slug) && $slug !== '' ? rtrim($this->publicBase, '/') . '/book/' . rawurlencode($slug) : null;
    }

    private static function name(mixed $stored): string
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        $text = LocalizedText::decode($stored);

        return (string) ($text['en'] ?? (reset($text) ?: ''));
    }
}
