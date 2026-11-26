<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Throwable;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Core\Storage\StoredFiles;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;

/**
 * The visit record (WHIT-1443, design section 39 §7.3, register AP.21).
 *
 * One record for each booking: the notes of the person treating the customer,
 * the forms sent for it, and files. Who may see it is decided by the caller
 * ({@see self::may()}): `calendar:visit_notes`, which no role holds until an
 * admin grants it, or being the treating staff member. Never customers, and an
 * agent only through that permission.
 *
 * FINISHED MEANS FINISHED. Until Finish visit the notes can be written over by
 * their author. After it they never change: {@see self::write()} refuses, its
 * UPDATE only touches an unfinished record, and there is no way to delete
 * notes, corrections or files at all. What is added later is a dated
 * correction, signed by who added it.
 *
 * Every read and every change is audited. Files are kept in the tenant's own
 * storage (`stored_files`, kind `visit_file`). Not a medical records system;
 * stored in the EU.
 */
final class VisitRecords
{
    public const STORAGE_SEGMENT = 'visits';
    /** Files on a visit: up to 20 MB, and these types only. */
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'text/plain' => 'txt',
    ];
    public const MAX_NOTES = 20000;

    public function __construct(
        private readonly PDO $db,
        private readonly IntakeForms $intake,
        private readonly ?StorageDriverInterface $storage,
        private readonly ?AuditLoggerInterface $audit,
        private readonly \Closure $clock,
    ) {
    }

    /**
     * Whether someone may see and write a booking's visit record: with
     * `calendar:visit_notes`, or as the person treating the customer. An
     * agent needs the permission itself.
     *
     * @param array<string, mixed> $booking
     */
    public static function may(array $booking, int $actor, bool $hasPermission, bool $isAgent): bool
    {
        if ($hasPermission) {
            return true;
        }

        return !$isAgent && $actor > 0 && $actor === (int) ($booking['profile_id'] ?? 0);
    }

    /**
     * The record, for someone allowed to see it. The read is audited.
     *
     * @return array<string, mixed>
     */
    public function read(int $tenantId, int $bookingId, int $actor): array
    {
        $notes = $this->notes($tenantId, $bookingId);
        $record = [
            'booking_id' => $bookingId,
            'notes' => $notes === null ? null : [
                'body' => (string) $notes['body'],
                'author' => $this->person($notes['author_profile_id']),
                'created_at' => Interval::toApi(Interval::fromDb((string) $notes['created_at'])),
                'updated_at' => Interval::toApi(Interval::fromDb((string) $notes['updated_at'])),
                'finished_at' => $notes['finished_at'] !== null ? Interval::toApi(Interval::fromDb((string) $notes['finished_at'])) : null,
                'finished_by' => $this->person($notes['finished_by_profile_id']),
            ],
            'finished' => $notes !== null && $notes['finished_at'] !== null,
            'corrections' => $notes === null ? [] : $this->corrections($tenantId, (int) $notes['id']),
            'files' => $this->files($tenantId, $bookingId),
            'forms' => array_map(static fn (array $s): array => $s + [
                // FP.1: the form's printout.
                'printout_path' => '/api/v1/form-submissions/' . $s['id'] . '/printout',
            ], $this->intake->submissionsOf($tenantId, $bookingId)),
        ];
        $this->record('calendar.visit.viewed', $tenantId, $actor, $bookingId, []);

        return $record;
    }

    /**
     * Write the notes. Refused once the visit is finished: add a correction.
     *
     * @return array<string, mixed>
     */
    public function write(int $tenantId, int $bookingId, string $body, int $actor): array
    {
        $body = self::clean($body);
        $now = Interval::toDb($this->now());
        $notes = $this->notes($tenantId, $bookingId);
        if ($notes !== null && $notes['finished_at'] !== null) {
            throw new CalendarRejectedException('This visit is finished. Its notes no longer change; add a correction instead.', 409, ['code' => 'visit_finished']);
        }
        if ($notes === null) {
            $this->db->prepare(
                'INSERT INTO calendar_visit_notes (tenant_id, booking_id, body, author_profile_id, created_at, updated_at)
                 VALUES (:tenant_id, :booking_id, :body, :author, :now, :now2)'
            )->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId, ':body' => $body, ':author' => $actor > 0 ? $actor : null, ':now' => $now, ':now2' => $now]);
        } else {
            // Only an unfinished record: a Finish visit in between wins.
            $stmt = $this->db->prepare(
                'UPDATE calendar_visit_notes SET body = :body, updated_at = :now
                  WHERE tenant_id = :tenant_id AND id = :id AND finished_at IS NULL'
            );
            $stmt->execute([':body' => $body, ':now' => $now, ':tenant_id' => $tenantId, ':id' => (int) $notes['id']]);
            if ($stmt->rowCount() === 0) {
                throw new CalendarRejectedException('This visit is finished. Its notes no longer change; add a correction instead.', 409, ['code' => 'visit_finished']);
            }
        }
        $this->record('calendar.visit.written', $tenantId, $actor, $bookingId, ['length' => mb_strlen($body)]);

        return $this->read($tenantId, $bookingId, $actor);
    }

    /**
     * Finish visit: the notes are signed and never change again.
     *
     * @return array<string, mixed>
     */
    public function finish(int $tenantId, int $bookingId, int $actor): array
    {
        $notes = $this->notes($tenantId, $bookingId);
        if ($notes === null) {
            throw new CalendarRejectedException('Write the visit notes first.', 422);
        }
        $stmt = $this->db->prepare(
            'UPDATE calendar_visit_notes SET finished_at = :now, finished_by_profile_id = :actor
              WHERE tenant_id = :tenant_id AND id = :id AND finished_at IS NULL'
        );
        $stmt->bindValue(':now', Interval::toDb($this->now()));
        $stmt->bindValue(':actor', $actor > 0 ? $actor : null, $actor > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', (int) $notes['id'], PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new CalendarRejectedException('This visit is already finished.', 409, ['code' => 'visit_finished']);
        }
        $this->record('calendar.visit.finished', $tenantId, $actor, $bookingId, []);

        return $this->read($tenantId, $bookingId, $actor);
    }

    /**
     * Add a dated correction to a finished visit. Before Finish visit the
     * notes themselves are written instead.
     *
     * @return array<string, mixed>
     */
    public function correct(int $tenantId, int $bookingId, string $body, int $actor): array
    {
        $body = self::clean($body);
        if ($body === '') {
            throw new CalendarRejectedException('Write the correction.', 422);
        }
        $notes = $this->notes($tenantId, $bookingId);
        if ($notes === null || $notes['finished_at'] === null) {
            throw new CalendarRejectedException('Corrections are added once the visit is finished. Until then, change the notes.', 422);
        }
        $this->db->prepare(
            'INSERT INTO calendar_visit_corrections (tenant_id, visit_id, body, author_profile_id, created_at)
             VALUES (:tenant_id, :visit_id, :body, :author, :now)'
        )->execute([':tenant_id' => $tenantId, ':visit_id' => (int) $notes['id'], ':body' => $body, ':author' => $actor > 0 ? $actor : null, ':now' => Interval::toDb($this->now())]);
        $this->record('calendar.visit.corrected', $tenantId, $actor, $bookingId, ['length' => mb_strlen($body)]);

        return $this->read($tenantId, $bookingId, $actor);
    }

    /**
     * Keep a file on the visit, in the tenant's own storage. Allowed before
     * and after Finish visit: a file is added, never replaced or removed.
     *
     * @return array<string, mixed> the file
     */
    public function addFile(int $tenantId, int $bookingId, string $bytes, ?string $declaredType, ?string $filename, int $actor): array
    {
        if ($this->storage === null) {
            throw new CalendarRejectedException('Files can\'t be kept here right now.', 503);
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new CalendarRejectedException('A file on a visit can be up to 20 MB.', 422);
        }
        $type = self::sniff($bytes, $declaredType);
        $ext = self::TYPES[$type] ?? null;
        if ($ext === null) {
            throw new CalendarRejectedException('Add a PDF, a photo or a text file.', 422);
        }
        try {
            $stored = StoredFiles::store($this->storage, $tenantId, self::STORAGE_SEGMENT, (string) $bookingId, $bytes, $type, $ext);
        } catch (StorageException) {
            throw new CalendarRejectedException('The file could not be kept. Please try again.', 503);
        }
        $storedId = StoredFiles::remember($this->db, $tenantId, StoredFiles::VISIT_FILE, $stored, $filename, $actor > 0 ? $actor : null);
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_visit_files (tenant_id, booking_id, stored_file_id, created_by_profile_id, created_at)
             VALUES (:tenant_id, :booking_id, :stored, :actor, :now) RETURNING id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
        $stmt->bindValue(':stored', $storedId, PDO::PARAM_INT);
        $stmt->bindValue(':actor', $actor > 0 ? $actor : null, $actor > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':now', Interval::toDb($this->now()));
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();
        $this->record('calendar.visit.file_added', $tenantId, $actor, $bookingId, ['file_id' => $id]);

        foreach ($this->files($tenantId, $bookingId) as $file) {
            if ($file['id'] === $id) {
                return $file;
            }
        }
        throw CalendarRejectedException::notFound('File');
    }

    /** The booking a visit file belongs to, so the caller can check who may open it. */
    public function bookingOfFile(int $tenantId, int $fileId): ?int
    {
        $stmt = $this->db->prepare('SELECT booking_id FROM calendar_visit_files WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $fileId]);
        $booking = $stmt->fetchColumn();

        return $booking === false ? null : (int) $booking;
    }

    /**
     * A visit file's bytes, for someone allowed to see the visit. Audited.
     *
     * @return array{bytes: string, content_type: string, filename: string}
     */
    public function fileBytes(int $tenantId, int $fileId, int $actor): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.booking_id, f.storage_key, f.content_type, f.filename FROM calendar_visit_files v
               JOIN stored_files f ON f.id = v.stored_file_id AND f.tenant_id = v.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $this->storage === null) {
            throw CalendarRejectedException::notFound('File');
        }
        $bytes = $this->storage->get((string) $row['storage_key']);
        $this->record('calendar.visit.file_read', $tenantId, $actor, (int) $row['booking_id'], ['file_id' => $fileId]);

        return ['bytes' => $bytes, 'content_type' => (string) $row['content_type'], 'filename' => (string) ($row['filename'] ?? 'file')];
    }

    // ── inside ──────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function notes(int $tenantId, int $bookingId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_visit_notes WHERE tenant_id = :tenant_id AND booking_id = :booking_id');
        $stmt->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array{id: int, body: string, author: ?array{profile_id: int, name: string}, created_at: string}> */
    private function corrections(int $tenantId, int $visitId): array
    {
        $stmt = $this->db->prepare('SELECT id, body, author_profile_id, created_at FROM calendar_visit_corrections WHERE tenant_id = :tenant_id AND visit_id = :visit_id ORDER BY created_at, id');
        $stmt->execute([':tenant_id' => $tenantId, ':visit_id' => $visitId]);

        return array_values(array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'body' => (string) $r['body'],
            'author' => $this->person($r['author_profile_id']),
            'created_at' => Interval::toApi(Interval::fromDb((string) $r['created_at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array{id: int, filename: string, content_type: string, byte_size: int, added_by: ?array{profile_id: int, name: string}, created_at: string}> */
    private function files(int $tenantId, int $bookingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, v.created_by_profile_id, v.created_at, f.filename, f.content_type, f.byte_size FROM calendar_visit_files v
               JOIN stored_files f ON f.id = v.stored_file_id AND f.tenant_id = v.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.booking_id = :booking_id ORDER BY v.created_at, v.id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId]);

        return array_values(array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'filename' => (string) ($r['filename'] ?? 'file'),
            'content_type' => (string) $r['content_type'],
            'byte_size' => (int) $r['byte_size'],
            'added_by' => $this->person($r['created_by_profile_id']),
            'created_at' => Interval::toApi(Interval::fromDb((string) $r['created_at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array{profile_id: int, name: string}|null someone shown by name, never an id alone */
    private function person(mixed $profileId): ?array
    {
        if ($profileId === null) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => (int) $profileId]);

        return ['profile_id' => (int) $profileId, 'name' => (string) ($stmt->fetchColumn() ?: '')];
    }

    /** @param array<string, mixed> $metadata */
    private function record(string $action, int $tenantId, int $actor, int $bookingId, array $metadata): void
    {
        try {
            $this->audit?->record($action, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor > 0 ? $actor : null,
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $e) {
            ScrubbedErrorLog::failure('[calendar] could not audit a visit record', $e);
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function clean(string $body): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) > self::MAX_NOTES) {
            throw new CalendarRejectedException('Notes can be up to 20,000 characters.', 422);
        }

        return $body;
    }

    /** The type from the bytes, so a renamed file can't pass as another. */
    private static function sniff(string $bytes, ?string $declared): string
    {
        $head = substr($bytes, 0, 16);

        return match (true) {
            str_starts_with($head, '%PDF') => 'application/pdf',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            substr($bytes, 4, 8) === 'ftypheic' => 'image/heic',
            $declared === 'text/plain' && mb_check_encoding($bytes, 'UTF-8') => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
