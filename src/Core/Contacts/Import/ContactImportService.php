<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

use PDO;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\Contacts\ContactTags;
use Whity\Core\Contacts\PhoneNumber;
use Whity\Core\Db\DbBool;

/**
 * Importing contacts from a spreadsheet (WHIT-953, design 3.3 A8, `TplImport`).
 *
 * 1. **Upload**: the file is read, its header row found, and the rows kept on
 *    the import while the person works. Each column comes back with examples
 *    and a suggested target.
 * 2. **Check**: with the columns matched, every row is worked out as create,
 *    update (matched by tax number, mobile, email or name) or skip with a
 *    reason, and what Whity tidied is listed. **Nothing changes.**
 * 3. **Import**: the same plan is worked out again against the contacts as
 *    they are now, and applied in one transaction. What each row did is
 *    recorded with the values before.
 * 4. **Undo**, for 24 hours: contacts the import made are removed, contacts it
 *    changed get their values back, and the tags it added come off.
 */
final class ContactImportService
{
    public const UNDO_SECONDS = 86400;
    public const MAX_LISTED = 200;

    /** Built-in targets, in the order the matcher offers them. */
    public const TARGETS = [
        'name' => 'Name',
        'contact_person' => 'Contact person',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'phone' => 'Phone',
        'tax_number' => 'Tax number',
        'payment_terms' => 'How they pay',
        'address' => 'Address',
        'notes' => 'Notes',
        'tags' => 'Tags',
        'type' => 'Customer or supplier',
    ];

    private const CUSTOMER_WORDS = ['customer', 'client', 'c', 'عميل', 'زبون', 'العميل'];
    private const SUPPLIER_WORDS = ['supplier', 'vendor', 's', 'مورد', 'المورد'];
    private const BOTH_WORDS = ['both', 'customer and supplier', 'كلاهما', 'عميل ومورد'];

    /** @var list<ImportTargets> */
    private array $extensions = [];

    public function __construct(
        private readonly PDO $db,
        private readonly ContactRepository $contacts,
        private readonly ContactTags $tags,
        private readonly ContactImportRepository $imports,
    ) {
    }

    public function addTargets(ImportTargets $targets): void
    {
        $this->extensions[] = $targets;
    }

    /**
     * Reads the file and keeps its rows on a new import.
     *
     * @return array<string, mixed> the import, API shape, with the columns
     */
    public function upload(int $tenantId, ?int $actor, string $fileName, string $bytes): array
    {
        $this->imports->sweepAbandoned($tenantId);
        $rows = SpreadsheetReader::read($bytes, $fileName);
        $headerAt = SpreadsheetReader::headerIndex($rows);
        $header = $rows[$headerAt];
        $data = array_slice($rows, $headerAt + 1);
        if ($data === []) {
            throw new ContactRejectedException('The file has a header row but no rows under it');
        }
        if (count($data) > SpreadsheetReader::MAX_ROWS) {
            throw new ContactRejectedException('The file has more than ' . SpreadsheetReader::MAX_ROWS . ' rows; import it in parts');
        }
        $id = $this->imports->create($tenantId, $actor, $fileName, $header, $data);

        return $this->show($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        $import = $this->imports->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Import');

        return $this->toApi($tenantId, $import);
    }

    /**
     * Works out what an import will do. Nothing changes.
     *
     * @param array<string, mixed> $input mapping, match_by, country_code, add_tags, default_kind
     * @return array<string, mixed> the import, API shape, with its summary
     */
    public function check(int $tenantId, int $id, array $input): array
    {
        $import = $this->imports->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Import');
        if (!in_array($import['status'], ['uploaded', 'checked'], true)) {
            throw new ContactRejectedException('This import has already run', 409);
        }
        $options = $this->options($tenantId, $import, $input);
        $plan = $this->plan($tenantId, $import['rows'], $options);
        $this->imports->saveCheck($tenantId, $id, $options, self::summary($plan));

        return $this->show($tenantId, $id);
    }

    /**
     * Runs a checked import.
     *
     * @return array<string, mixed> the import, API shape
     */
    public function run(int $tenantId, int $id, ?int $actor): array
    {
        $this->db->beginTransaction();
        try {
            $import = $this->imports->find($tenantId, $id, true) ?? throw ContactRejectedException::notFound('Import');
            if ($import['status'] !== 'checked') {
                throw new ContactRejectedException(
                    $import['status'] === 'uploaded' ? 'Check the import before running it' : 'This import has already run',
                    409,
                );
            }
            $options = $import['options'];
            $plan = $this->plan($tenantId, $import['rows'], $options);
            foreach ($plan as $row) {
                if ($row['action'] === 'create') {
                    $contactId = $this->contacts->insert($tenantId, $row['fields'], $actor);
                    $added = $this->tags->add($tenantId, $contactId, $row['tags']);
                    $this->writeExtra($tenantId, $contactId, $row['extra']);
                    $this->imports->recordChange($tenantId, $id, $contactId, 'created', null, $added);
                } elseif ($row['action'] === 'update') {
                    $contactId = (int) ($row['contact_id'] ?? 0);
                    $before = $row['before'];
                    $this->contacts->update($tenantId, $contactId, $row['fields']);
                    $added = $this->tags->add($tenantId, $contactId, $row['tags']);
                    $this->writeExtra($tenantId, $contactId, $row['extra']);
                    $this->imports->recordChange($tenantId, $id, $contactId, 'updated', $before, $added);
                }
            }
            $this->imports->markImported($tenantId, $id, self::summary($plan));
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->show($tenantId, $id);
    }

    /**
     * Undoes an import within 24 hours of it running.
     *
     * @return array<string, mixed> the import, API shape
     */
    public function undo(int $tenantId, int $id): array
    {
        $this->db->beginTransaction();
        try {
            $import = $this->imports->find($tenantId, $id, true) ?? throw ContactRejectedException::notFound('Import');
            if ($import['status'] !== 'imported' || $import['imported_at'] === null) {
                throw new ContactRejectedException('Only an import that ran can be undone', 409);
            }
            if (!$this->undoable($import['imported_at'])) {
                throw new ContactRejectedException('An import can be undone for 24 hours after it runs; this one ran longer ago', 409);
            }
            foreach ($this->imports->changes($tenantId, $id) as $change) {
                $contactId = $change['contact_id'];
                if ($change['action'] === 'created') {
                    $this->contacts->delete($tenantId, $contactId);
                    continue;
                }
                $before = $change['before'] ?? [];
                $fields = array_intersect_key($before, ContactRepository::TEXT_FIELDS + ['is_customer' => 1, 'is_supplier' => 1]);
                $this->contacts->update($tenantId, $contactId, $fields);
                $this->tags->remove($tenantId, $contactId, $change['tags_added']);
                $extra = is_array($before['extra'] ?? null) ? $before['extra'] : [];
                $this->writeExtra($tenantId, $contactId, $extra);
            }
            $this->imports->markUndone($tenantId, $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->show($tenantId, $id);
    }

    /**
     * The skipped rows as a CSV, each with its reason, to fix and import again.
     */
    public function skippedCsv(int $tenantId, int $id): string
    {
        $import = $this->imports->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Import');
        if ($import['options'] === []) {
            throw new ContactRejectedException('Check the import first', 409);
        }
        $plan = $this->plan($tenantId, $import['rows'], $import['options']);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('temp stream');
        }
        fwrite($stream, "\u{FEFF}");
        \Whity\Http\CsvCell::writeRow($stream, array_merge(['Row', 'Reason'], $import['header']));
        foreach ($plan as $row) {
            if ($row['action'] === 'skip') {
                \Whity\Http\CsvCell::writeRow($stream, array_merge([(string) $row['row'], (string) ($row['reason'] ?? '')], $import['rows'][$row['row'] - 2] ?? []));
            }
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $tenantId): array
    {
        $now = $this->imports->now();

        return array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'file_name' => (string) $r['file_name'],
            'status' => (string) $r['status'],
            'summary' => self::counts(json_decode((string) $r['summary_json'], true)),
            'created_by_profile_id' => $r['created_by_profile_id'] === null ? null : (int) $r['created_by_profile_id'],
            'imported_at' => $r['imported_at'],
            'undo_until' => $r['imported_at'] !== null && $r['status'] === 'imported' ? $this->undoUntil((string) $r['imported_at']) : null,
            'can_undo' => $r['status'] === 'imported' && $r['imported_at'] !== null && $this->undoable((string) $r['imported_at'], $now),
            'created_at' => (string) $r['created_at'],
        ], $this->imports->recent($tenantId, 20));
    }

    /**
     * Every target a column can go to in this workspace.
     *
     * @return list<array{key: string, label: string}>
     */
    public function targets(int $tenantId): array
    {
        $out = [];
        foreach (self::TARGETS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }
        foreach ($this->extensions as $extension) {
            foreach ($extension->targets($tenantId) as $key => $target) {
                $out[] = ['key' => $key, 'label' => $target['label']];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    private function toApi(int $tenantId, array $import): array
    {
        $extraNames = [];
        foreach ($this->extensions as $extension) {
            foreach ($extension->targets($tenantId) as $key => $target) {
                $extraNames[$key] = array_merge([$target['label']], $target['names']);
            }
        }
        $summary = $import['summary'];
        /** @var list<list<string>> $sample */
        $sample = array_slice($import['rows'], 0, 200);
        /** @var list<string> $header */
        $header = $import['header'];

        return [
            'id' => $import['id'],
            'file_name' => $import['file_name'],
            'status' => $import['status'],
            'row_count' => count($import['rows']),
            'columns' => $import['status'] === 'undone' ? [] : ColumnMatcher::suggest($header, $sample, $extraNames),
            'targets' => $this->targets($tenantId),
            'options' => $import['options'] === [] ? null : $import['options'],
            'summary' => $summary === [] ? null : $summary,
            'imported_at' => $import['imported_at'],
            'undone_at' => $import['undone_at'],
            'undo_until' => $import['imported_at'] !== null && $import['status'] === 'imported' ? $this->undoUntil($import['imported_at']) : null,
            'can_undo' => $import['status'] === 'imported' && $import['imported_at'] !== null && $this->undoable($import['imported_at']),
            'created_at' => $import['created_at'],
        ];
    }

    /**
     * Checks the options the person chose.
     *
     * @param array<string, mixed> $import
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function options(int $tenantId, array $import, array $input): array
    {
        $valid = [];
        foreach ($this->targets($tenantId) as $t) {
            $valid[$t['key']] = true;
        }
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === []) {
            throw new ContactRejectedException('Match at least one column', 422, ['field' => 'mapping']);
        }
        $width = count($import['header']);
        foreach ($import['rows'] as $row) {
            $width = max($width, count($row));
        }
        $clean = [];
        $used = [];
        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }
            if (!is_numeric($index) || (int) $index < 0 || (int) $index >= $width) {
                throw new ContactRejectedException('Column ' . $index . ' is not in the file', 422, ['field' => 'mapping']);
            }
            if (!is_string($target) || !isset($valid[$target])) {
                throw new ContactRejectedException('Column ' . $index . ' goes to a field Whity doesn\'t know', 422, ['field' => 'mapping']);
            }
            if ($target !== 'tags' && isset($used[$target])) {
                throw new ContactRejectedException('Two columns go to ' . (self::TARGETS[$target] ?? $target) . '; choose one', 422, ['field' => 'mapping']);
            }
            $used[$target] = true;
            $clean[(string) (int) $index] = $target;
        }
        if (!isset($used['name'])) {
            throw new ContactRejectedException('Choose the column with the contact\'s name', 422, ['field' => 'mapping']);
        }

        $matchBy = $input['match_by'] ?? null;
        if ($matchBy === null || $matchBy === '') {
            $matchBy = null;
            foreach (['tax_number', 'mobile', 'email'] as $key) {
                if (isset($used[$key])) {
                    $matchBy = $key;
                    break;
                }
            }
        } elseif (!is_string($matchBy) || !in_array($matchBy, ContactRepository::MATCH_KEYS, true)) {
            throw new ContactRejectedException('match_by must be one of ' . implode(', ', ContactRepository::MATCH_KEYS), 422, ['field' => 'match_by']);
        } elseif (!isset($used[$matchBy])) {
            throw new ContactRejectedException('To match by ' . self::TARGETS[$matchBy] . ', choose its column', 422, ['field' => 'match_by']);
        }

        $country = $input['country_code'] ?? null;
        if ($country !== null && $country !== '') {
            $country = is_string($country) ? PhoneNumber::countryCode($country) : null;
            if ($country === null) {
                throw new ContactRejectedException('country_code must look like +962', 422, ['field' => 'country_code']);
            }
        } else {
            $country = null;
        }

        $kind = $input['default_kind'] ?? 'customer';
        if (!in_array($kind, ['customer', 'supplier', 'both'], true)) {
            throw new ContactRejectedException('default_kind must be customer, supplier or both', 422, ['field' => 'default_kind']);
        }

        $addTags = $input['add_tags'] ?? [];
        $tags = [];
        if (!is_array($addTags)) {
            throw new ContactRejectedException('add_tags must be a list of names', 422, ['field' => 'add_tags']);
        }
        foreach ($addTags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = ContactTags::clean($tag);
            }
        }

        return [
            'mapping' => $clean,
            'match_by' => $matchBy,
            'country_code' => $country,
            'default_kind' => $kind,
            'add_tags' => array_slice(array_values(array_unique($tags)), 0, 10),
        ];
    }

    /**
     * What each row will do.
     *
     * @param list<list<string>> $rows
     * @param array<string, mixed> $options
     * @return list<array{row: int, action: string, reason?: string, contact_id?: int, fields: array<string, mixed>, extra: array<string, mixed>, tags: list<string>, before: ?array<string, mixed>, changes: list<string>, fixes: list<array{column: string, from: string, to: string}>, name: string}>
     */
    private function plan(int $tenantId, array $rows, array $options): array
    {
        /** @var array<string, string> $mapping */
        $mapping = $options['mapping'];
        $matchBy = $options['match_by'];
        $country = $options['country_code'];
        $extraTargets = [];
        foreach ($this->extensions as $extension) {
            foreach (array_keys($extension->targets($tenantId)) as $key) {
                $extraTargets[$key] = $extension;
            }
        }

        $plan = [];
        $seen = [];
        foreach ($rows as $i => $cells) {
            $rowNumber = $i + 2;
            $fields = [];
            $extra = [];
            $tags = $options['add_tags'];
            $fixes = [];
            $error = null;
            $kind = null;
            foreach ($mapping as $index => $target) {
                $raw = trim((string) ($cells[(int) $index] ?? ''));
                if ($target === 'tags') {
                    foreach (preg_split('/[,;|،]/u', $raw) ?: [] as $t) {
                        if (trim($t) !== '') {
                            $tags[] = ContactTags::clean($t);
                        }
                    }
                    continue;
                }
                if ($target === 'type') {
                    $kind = self::kind($raw);
                    continue;
                }
                if (isset($extraTargets[$target])) {
                    if ($raw === '') {
                        continue;
                    }
                    $checked = $extraTargets[$target]->check($tenantId, $target, $raw);
                    if (isset($checked['error'])) {
                        $error ??= $checked['error'];
                    } else {
                        $extra[$target] = $checked['value'];
                    }
                    continue;
                }
                $max = ContactRepository::TEXT_FIELDS[$target];
                if (mb_strlen($raw) > $max) {
                    $error ??= self::TARGETS[$target] . ' is longer than ' . $max . ' characters';
                    continue;
                }
                if ($target === 'email' && $raw !== '') {
                    $raw = mb_strtolower($raw);
                    if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                        $error ??= 'The email address doesn\'t look right';
                        continue;
                    }
                }
                if (($target === 'mobile' || $target === 'phone') && $raw !== '') {
                    $tidy = PhoneNumber::tidy($raw, $country);
                    if ($tidy === null) {
                        $error ??= 'The ' . strtolower(self::TARGETS[$target]) . ' number doesn\'t look right';
                        continue;
                    }
                    if ($tidy['value'] !== $raw) {
                        $fixes[] = ['column' => $target, 'from' => $raw, 'to' => $tidy['value']];
                    }
                    $raw = $tidy['value'];
                }
                $fields[$target] = $raw;
            }
            $name = (string) ($fields['name'] ?? '');
            $base = ['row' => $rowNumber, 'fields' => [], 'extra' => [], 'tags' => [], 'before' => null, 'changes' => [], 'fixes' => $fixes, 'name' => $name];
            if ($error !== null) {
                $plan[] = ['action' => 'skip', 'reason' => $error] + $base;
                continue;
            }
            if ($name === '') {
                $plan[] = ['action' => 'skip', 'reason' => 'No name'] + $base;
                continue;
            }
            if ($kind === false) {
                $plan[] = ['action' => 'skip', 'reason' => 'Customer or supplier is not one Whity understands'] + $base;
                continue;
            }
            $kind ??= $options['default_kind'];
            $fields['is_customer'] = $kind === 'customer' || $kind === 'both';
            $fields['is_supplier'] = $kind === 'supplier' || $kind === 'both';
            $tags = array_values(array_unique($tags));

            $key = $matchBy !== null ? mb_strtolower((string) ($fields[$matchBy] ?? '')) : '';
            $key = $key === '' ? 'name:' . mb_strtolower($name) : $matchBy . ':' . $key;
            if (isset($seen[$key])) {
                $plan[] = ['action' => 'skip', 'reason' => 'Same contact as row ' . $seen[$key]] + $base;
                continue;
            }
            $seen[$key] = $rowNumber;

            $existing = null;
            if ($matchBy !== null && ($fields[$matchBy] ?? '') !== '') {
                $existing = $this->contacts->findBy($tenantId, $matchBy, (string) $fields[$matchBy]);
            }
            if ($existing === null) {
                $plan[] = ['action' => 'create', 'fields' => $fields, 'extra' => $extra, 'tags' => $tags] + $base;
                continue;
            }

            // An update writes what the row has and the contact doesn't, and
            // never empties a field: a blank cell means "nothing to say".
            $changed = [];
            $changes = [];
            foreach ($fields as $field => $value) {
                if ($field === 'is_customer' || $field === 'is_supplier') {
                    // A row can add a role to a contact; it never takes one away.
                    if ($value === true && !DbBool::of($existing[$field])) {
                        $changed[$field] = true;
                        $changes[] = $field;
                    }
                    continue;
                }
                if ($value !== '' && (string) $existing[$field] !== $value) {
                    $changed[$field] = $value;
                    $changes[] = $field;
                }
            }
            $extraBefore = [];
            if ($extra !== []) {
                foreach ($this->extensions as $extension) {
                    $mine = array_intersect_key($extra, $extension->targets($tenantId));
                    if ($mine !== []) {
                        $extraBefore += $extension->current($tenantId, (int) $existing['id'], array_keys($mine));
                    }
                }
                foreach ($extra as $target => $value) {
                    if (($extraBefore[$target] ?? null) !== $value) {
                        $changes[] = $target;
                    } else {
                        unset($extra[$target]);
                    }
                }
            }
            $newTags = [];
            if ($tags !== []) {
                $have = [];
                foreach ($this->contacts->tagsFor($tenantId, [(int) $existing['id']])[(int) $existing['id']] ?? [] as $t) {
                    $have[mb_strtolower($t['name'])] = true;
                }
                foreach ($tags as $t) {
                    if (!isset($have[mb_strtolower($t)])) {
                        $newTags[] = $t;
                    }
                }
            }
            if ($changes === [] && $newTags === []) {
                $plan[] = ['action' => 'skip', 'reason' => 'Already up to date', 'contact_id' => (int) $existing['id']] + $base;
                continue;
            }
            $before = array_intersect_key($existing, $changed);
            $before['extra'] = array_intersect_key($extraBefore, $extra);
            $plan[] = [
                'action' => 'update',
                'contact_id' => (int) $existing['id'],
                'fields' => $changed,
                'extra' => $extra,
                'tags' => $newTags,
                'before' => $before,
                'changes' => $changes,
            ] + $base;
        }

        return $plan;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan): array
    {
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0];
        $rows = [];
        $fixes = [];
        $tags = [];
        foreach ($plan as $row) {
            $counts[$row['action']]++;
            if (count($rows) < self::MAX_LISTED) {
                $rows[] = array_filter([
                    'row' => $row['row'],
                    'action' => $row['action'],
                    'name' => $row['name'],
                    'contact_id' => $row['contact_id'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'changes' => $row['changes'] !== [] ? $row['changes'] : null,
                ], static fn ($v): bool => $v !== null);
            }
            if ($row['action'] !== 'skip') {
                foreach ($row['fixes'] as $fix) {
                    if (count($fixes) < self::MAX_LISTED) {
                        $fixes[] = ['row' => $row['row']] + $fix;
                    }
                }
                foreach ($row['tags'] as $t) {
                    $tags[mb_strtolower($t)] = $t;
                }
            }
        }

        return [
            'create' => $counts['create'],
            'update' => $counts['update'],
            'skip' => $counts['skip'],
            'rows' => $rows,
            'fixes' => $fixes,
            'tags' => array_values($tags),
        ];
    }

    /** @return array{create: int, update: int, skip: int}|null */
    private static function counts(mixed $summary): ?array
    {
        if (!is_array($summary) || !isset($summary['create'])) {
            return null;
        }

        return ['create' => (int) $summary['create'], 'update' => (int) $summary['update'], 'skip' => (int) $summary['skip']];
    }

    /** customer, supplier or both; null for an empty cell; false when the word is not understood. */
    private static function kind(string $raw): string|false|null
    {
        $w = mb_strtolower(trim($raw));
        if ($w === '') {
            return null;
        }
        if (in_array($w, self::BOTH_WORDS, true)) {
            return 'both';
        }
        if (in_array($w, self::CUSTOMER_WORDS, true)) {
            return 'customer';
        }
        if (in_array($w, self::SUPPLIER_WORDS, true)) {
            return 'supplier';
        }

        return false;
    }

    /** @param array<string, mixed> $values */
    private function writeExtra(int $tenantId, int $contactId, array $values): void
    {
        if ($values === []) {
            return;
        }
        foreach ($this->extensions as $extension) {
            $mine = array_intersect_key($values, $extension->targets($tenantId));
            if ($mine !== []) {
                $extension->write($tenantId, $contactId, $mine);
            }
        }
    }

    private function undoable(string $importedAt, ?int $now = null): bool
    {
        $at = strtotime($importedAt);

        return $at !== false && ($now ?? $this->imports->now()) - $at <= self::UNDO_SECONDS;
    }

    private function undoUntil(string $importedAt): ?string
    {
        $at = strtotime($importedAt);

        return $at === false ? null : gmdate('Y-m-d\TH:i:s\Z', $at + self::UNDO_SECONDS);
    }
}
