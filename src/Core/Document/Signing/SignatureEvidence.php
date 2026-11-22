<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteTrailChain;

/**
 * What a route's final PDF carries about its signatures (#1730): a signature
 * block, the stamp of each company stamp applied, and an evidence page that
 * sums up the trail. Built as Whity Render blocks (`signatureRow`, `figure`,
 * `table`, `notice`, `pageBreak`), so the render service needs no change.
 *
 * In each signature row: the drawn signature, the name, the capacity (and on
 * whose behalf), the date and a short hash of the sealed record. The full
 * record, its seal and the instance's public key stay on the audit page; the
 * short hash lets a reader match the paper to it.
 *
 * The evidence page lists the acts on the route (when, who, what, which version
 * and a short hash of each trail entry) and says whether the trail's chain is
 * intact. Notes, email addresses, IP addresses and browsers are left out: the
 * PDF travels, and those stay with people allowed to read the audit.
 *
 * The words are in code, in English and Arabic, as for form printouts: the
 * worker that makes a final document has no request language.
 */
final class SignatureEvidence
{
    /** How much of a hash is printed. 16 hex characters: 64 bits, enough to match a record by eye. */
    public const SHORT_HASH = 16;

    private const MAX_ROWS = 300;

    private const WORDS = [
        'en' => [
            'signatures' => 'Signatures',
            'signedOn' => 'Signed electronically on {at} · {hash}',
            'stampedOn' => 'Applied on {at} by {name} · {hash}',
            'stamp' => 'Company stamp',
            'onBehalf' => '{capacity}, for {party}',
            'evidence' => 'Evidence',
            'evidenceIntro' => 'Electronic signatures with an audit trail. Each signature and stamp is bound to the SHA-256 of the version shown and sealed by this installation. The full record is on the document’s audit page.',
            'when' => 'When (UTC)',
            'who' => 'Who',
            'what' => 'What',
            'version' => 'Version',
            'entry' => 'Entry',
            'intact' => 'The trail’s chain is intact: no entry has been changed or removed since it was written.',
            'broken' => 'The trail’s chain is broken: an entry was changed or removed after it was written. Do not rely on this document without checking with its issuer.',
            'chainLabel' => 'Trail',
            'contentHash' => 'Signed content SHA-256: {hash}',
            'stampNote' => 'A company stamp here is an authorised person applying the image of the stamp, not a certified electronic seal.',
            'nobody' => 'Someone no longer in the workspace',
            'action.issued' => 'Sent',
            'action.forwarded' => 'Passed on',
            'action.acknowledged' => 'Answered',
            'action.returned' => 'Returned',
            'action.noted' => 'Note',
            'action.sent_back' => 'Sent back',
            'action.resubmitted' => 'Resubmitted',
            'action.signed' => 'Signed',
            'action.stamped' => 'Stamp applied',
            'action.declined' => 'Declined',
            'verdict.approved' => 'Approved',
            'verdict.rejected' => 'Not approved',
        ],
        'ar' => [
            'signatures' => 'التواقيع',
            'signedOn' => 'وُقّع إلكترونيًا في {at} · {hash}',
            'stampedOn' => 'خُتم في {at} بيد {name} · {hash}',
            'stamp' => 'ختم الشركة',
            'onBehalf' => '{capacity}، عن {party}',
            'evidence' => 'الإثبات',
            'evidenceIntro' => 'تواقيع إلكترونية مع سجل تدقيق. كل توقيع وختم مرتبط ببصمة SHA-256 للنسخة المعروضة ومختوم من هذا التثبيت. السجل الكامل في صفحة التدقيق للمستند.',
            'when' => 'الوقت (UTC)',
            'who' => 'مَن',
            'what' => 'ماذا',
            'version' => 'النسخة',
            'entry' => 'القيد',
            'intact' => 'سلسلة السجل سليمة: لم يُغيَّر أي قيد ولم يُحذف منذ كتابته.',
            'broken' => 'سلسلة السجل مكسورة: غُيّر قيد أو حُذف بعد كتابته. لا تعتمد على هذا المستند قبل التحقق من الجهة التي أصدرته.',
            'chainLabel' => 'السجل',
            'contentHash' => 'بصمة SHA-256 للمحتوى الموقّع: {hash}',
            'stampNote' => 'ختم الشركة هنا هو تطبيق شخص مخوَّل لصورة الختم، وليس ختمًا إلكترونيًا معتمدًا.',
            'nobody' => 'شخص لم يعد في مساحة العمل',
            'action.issued' => 'أُرسل',
            'action.forwarded' => 'مُرِّر',
            'action.acknowledged' => 'أُجيب',
            'action.returned' => 'أُعيد',
            'action.noted' => 'ملاحظة',
            'action.sent_back' => 'أُعيد للتعديل',
            'action.resubmitted' => 'أُعيد تقديمه',
            'action.signed' => 'وُقّع',
            'action.stamped' => 'خُتم',
            'action.declined' => 'رُفض التوقيع',
            'verdict.approved' => 'تمت الموافقة',
            'verdict.rejected' => 'لم تتم الموافقة',
        ],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The blocks for a route's final PDF, or [] when nobody signed or stamped on it.
     *
     * @return list<array<string, mixed>>
     */
    public function blocksForRoute(int $tenantId, int $routeId, string $lang): array
    {
        $rows = (new SignatureRepository($this->db))->listForRoute($tenantId, $routeId);
        if ($rows === []) {
            return [];
        }
        $documentId = (int) $rows[0]['document_id'];

        return self::blocks(
            $rows,
            $this->trail($tenantId, $routeId),
            RouteTrailChain::verify($this->db, $tenantId, $documentId)['intact'],
            $lang,
        );
    }

    /**
     * Pure: the blocks from the records, the route's trail and the chain check.
     *
     * @param list<array<string, mixed>> $rows  From {@see SignatureRepository}.
     * @param list<array{occurred_at: string, actor: ?string, action: string, verdict: ?string,
     *                   subject_version: ?int, entry_hash: ?string}> $trail
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $rows, array $trail, bool $intact, string $lang): array
    {
        $w = self::WORDS[$lang === 'ar' ? 'ar' : 'en'];
        $blocks = [['type' => 'heading', 'level' => 2, 'text' => $w['signatures']]];
        $hasStamp = false;
        $contentHashes = [];

        foreach ($rows as $row) {
            $at = self::when((string) $row['signed_at']);
            $hash = self::short((string) $row['record_hash']);
            $contentHashes[(string) $row['content_hash']] = true;
            if (($row['kind'] ?? SignatureRepository::KIND_SIGNATURE) === SignatureRepository::KIND_STAMP) {
                $hasStamp = true;
                $blocks[] = [
                    'type' => 'figure',
                    'src' => (string) $row['appearance'],
                    'alt' => (string) ($row['stamp_name'] ?? $w['stamp']),
                    'heightMm' => 32,
                    'label' => $w['stamp'],
                    'caption' => trim((string) ($row['stamp_legal_name'] ?? '')) . ' · ' . strtr($w['stampedOn'], [
                        '{at}' => $at,
                        '{name}' => (string) $row['signer_name'],
                        '{hash}' => $hash,
                    ]),
                ];
                continue;
            }

            $capacity = isset($row['capacity']) ? trim((string) $row['capacity']) : '';
            $party = isset($row['on_behalf_of']) ? trim((string) $row['on_behalf_of']) : '';
            $role = $capacity !== '' && $party !== ''
                ? strtr($w['onBehalf'], ['{capacity}' => $capacity, '{party}' => $party])
                : ($capacity !== '' ? $capacity : $party);
            $block = [
                'type' => 'signatureRow',
                'name' => (string) $row['signer_name'],
                'confirmed' => strtr($w['signedOn'], ['{at}' => $at, '{hash}' => $hash]),
            ];
            if ($role !== '') {
                $block['role'] = $role;
            }
            $drawing = self::drawing(json_decode((string) $row['appearance'], true));
            if ($drawing !== null) {
                $block['drawing'] = $drawing;
            }
            $blocks[] = $block;
        }

        $blocks[] = ['type' => 'pageBreak'];
        $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => $w['evidence']];
        $blocks[] = ['type' => 'paragraph', 'text' => $w['evidenceIntro']];
        foreach (array_keys($contentHashes) as $contentHash) {
            $blocks[] = ['type' => 'paragraph', 'text' => strtr($w['contentHash'], ['{hash}' => $contentHash])];
        }
        $blocks[] = ['type' => 'notice', 'label' => $w['chainLabel'], 'text' => $intact ? $w['intact'] : $w['broken']];

        $tableRows = [];
        foreach (array_slice($trail, 0, self::MAX_ROWS) as $entry) {
            $what = $w['action.' . $entry['action']] ?? $entry['action'];
            if ($entry['verdict'] !== null && isset($w['verdict.' . $entry['verdict']])
                && !in_array($entry['action'], [RouteAction::SIGNED, RouteAction::STAMPED, RouteAction::DECLINED], true)) {
                $what .= ' · ' . $w['verdict.' . $entry['verdict']];
            }
            $tableRows[] = [
                self::when($entry['occurred_at']),
                $entry['actor'] ?? $w['nobody'],
                $what,
                $entry['subject_version'] !== null ? (string) $entry['subject_version'] : '',
                $entry['entry_hash'] !== null ? self::short($entry['entry_hash']) : '',
            ];
        }
        if ($tableRows !== []) {
            $blocks[] = [
                'type' => 'table',
                'columns' => [$w['when'], $w['who'], $w['what'], $w['version'], $w['entry']],
                'rows' => $tableRows,
            ];
        }
        if ($hasStamp) {
            $blocks[] = ['type' => 'paragraph', 'text' => $w['stampNote']];
        }

        return $blocks;
    }

    /**
     * A drawn signature as Whity Render takes it: the strokes as SVG path data
     * in their own box ({@see SignatureAppearance}).
     *
     * @return array{viewBox: array{0: int, 1: int}, paths: list<string>}|null
     */
    public static function drawing(mixed $appearance): ?array
    {
        if (!is_array($appearance)) {
            return null;
        }
        $w = $appearance['width'] ?? null;
        $h = $appearance['height'] ?? null;
        if (!is_int($w) || !is_int($h) || $w < 1 || $h < 1 || !is_array($appearance['strokes'] ?? null)) {
            return null;
        }
        $paths = [];
        foreach (array_slice($appearance['strokes'], 0, 200) as $stroke) {
            $d = '';
            foreach (is_array($stroke) ? $stroke : [] as $point) {
                if (is_array($point) && count($point) === 2 && is_numeric($point[0]) && is_numeric($point[1])) {
                    $d .= ($d === '' ? 'M' : ' L') . round((float) $point[0], 1) . ' ' . round((float) $point[1], 1);
                }
            }
            if ($d !== '') {
                // A single point is a dot: a zero-length line with round caps.
                $paths[] = str_contains($d, 'L') ? $d : $d . ' l0.1 0';
            }
        }

        return $paths === [] ? null : ['viewBox' => [$w, $h], 'paths' => $paths];
    }

    public static function short(string $hash): string
    {
        return substr($hash, 0, self::SHORT_HASH);
    }

    /** 27 Oct 2026 14:05 — the register's date format, 24-hour, in UTC. */
    private static function when(string $at): string
    {
        $time = strtotime(str_replace('T', ' ', substr($at, 0, 19)) . ' UTC');

        return $time === false ? $at : gmdate('j M Y H:i', $time);
    }

    /**
     * The route's trail, oldest first, with each actor's name.
     *
     * @return list<array{occurred_at: string, actor: ?string, action: string, verdict: ?string,
     *                    subject_version: ?int, entry_hash: ?string}>
     */
    private function trail(int $tenantId, int $routeId): array
    {
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1).
        $stmt = $this->db->prepare(
            'SELECT e.occurred_at, e.action, e.verdict, e.subject_version, e.entry_hash, p.display_name
               FROM document_route_events e
               LEFT JOIN profiles p ON p.id = e.actor_profile_id
              WHERE e.tenant_id = :tenant_id AND e.route_id = :route_id
              ORDER BY e.id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'occurred_at' => (string) $row['occurred_at'],
                'actor' => isset($row['display_name']) && $row['display_name'] !== '' ? (string) $row['display_name'] : null,
                'action' => (string) $row['action'],
                'verdict' => isset($row['verdict']) ? (string) $row['verdict'] : null,
                'subject_version' => isset($row['subject_version']) ? (int) $row['subject_version'] : null,
                'entry_hash' => isset($row['entry_hash']) ? (string) $row['entry_hash'] : null,
            ];
        }

        return $out;
    }
}
