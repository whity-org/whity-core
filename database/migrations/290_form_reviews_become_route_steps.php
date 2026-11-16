<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * FormReviewsBecomeRouteSteps — the office records its part at a route step
 * (merge map A3).
 *
 * Until now a form could carry "staff" questions (`form_fields.audience`,
 * migration 194) that the office answered in a separate review
 * (`form_submission_reviews`), beside the route. Now the office answers them
 * where it reviews: as values a step of the form's route asks for
 * (`document_route_template_steps.inputs`, WHIT-1016). A people question becomes
 * a person value, so a later step's recusal still reads it (WHIT-1028).
 *
 * For each form with staff questions and a route template:
 *  - each staff question becomes a value on the template's first step people
 *    act on (a people picker becomes a person value), unless that step already
 *    asks for the key, up to the five values a step may ask for;
 *  - routes already made from that template get the same on the same step.
 * AAU IRB's "Human Research Ethics Application" is the one live case: its
 * supervisor and co-supervisor move to "Pre-check by the IRB coordinator", and
 * the committee step's recusal reads them from there.
 *
 * Then the staff questions, `form_fields.audience` and `form_submission_reviews`
 * go. There are no reviews on app.whity.dev and no customers to carry
 * (owner, 2026-11-16), so old reviews are not copied.
 *
 * Safe to run twice: once the column is gone there is nothing left to move.
 * Tables spelled out literally for CoreTablesTest and TenantOwnedTablesTest.
 */
final class FormReviewsBecomeRouteSteps
{
    private const MAX_INPUTS = 5;

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (self::columnExists($pdo, $driver, 'form_fields', 'audience')) {
            self::moveStaffQuestions($pdo);
            // Unscoped by design (migration): every workspace's staff questions go.
            $db->exec("DELETE FROM form_fields WHERE audience = 'staff'");
            $db->exec('ALTER TABLE form_fields DROP COLUMN IF EXISTS audience');
        }
        $db->exec('DROP TABLE IF EXISTS form_submission_reviews');
    }

    public static function down(Database $db): void
    {
        // The table and column come back empty; the moved questions stay on
        // their route steps.
        $db->exec("ALTER TABLE form_fields ADD COLUMN IF NOT EXISTS audience VARCHAR(16) NOT NULL DEFAULT 'applicant'");
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_submission_reviews (
                id                     BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id              INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                submission_id          BIGINT    NOT NULL REFERENCES form_submissions(id) ON DELETE CASCADE,
                data                   JSONB     NOT NULL DEFAULT '{}'::jsonb,
                recorded_by_profile_id INTEGER   REFERENCES profiles(id) ON DELETE SET NULL,
                recorded_at            TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }

    private static function moveStaffQuestions(PDO $pdo): void
    {
        // Unscoped by design (migration): reads every workspace's staff questions.
        $fields = $pdo->query(
            "SELECT f.tenant_id, f.form_id, f.field_key, f.field_type, f.label, f.is_required, fm.route_template_id
               FROM form_fields f
               JOIN forms fm ON fm.id = f.form_id AND fm.tenant_id = f.tenant_id
              WHERE f.audience = 'staff' AND fm.route_template_id IS NOT NULL
              ORDER BY f.tenant_id, f.form_id, f.position, f.id"
        );
        if ($fields === false) {
            return;
        }
        $byTemplate = [];
        foreach ($fields->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $byTemplate[(int) $f['tenant_id'] . ':' . (int) $f['route_template_id']][] = $f;
        }

        foreach ($byTemplate as $key => $questions) {
            [$tenantId, $templateId] = array_map('intval', explode(':', $key));
            $step = $pdo->prepare(
                "SELECT id, position, inputs FROM document_route_template_steps
                  WHERE tenant_id = :tenant_id AND template_id = :template_id
                    AND (kind IS NULL OR kind = '') AND COALESCE(satisfied_by, 'act') = 'act'
                  ORDER BY position ASC LIMIT 1"
            );
            $step->execute([':tenant_id' => $tenantId, ':template_id' => $templateId]);
            $first = $step->fetch(PDO::FETCH_ASSOC);
            $step->closeCursor();
            if (!is_array($first)) {
                continue;
            }
            $inputs = self::withQuestions(self::decode($first['inputs']), $questions);
            $pdo->prepare('UPDATE document_route_template_steps SET inputs = :inputs WHERE id = :id AND tenant_id = :tenant_id')
                ->execute([':inputs' => json_encode($inputs, JSON_UNESCAPED_UNICODE), ':id' => (int) $first['id'], ':tenant_id' => $tenantId]);

            // Routes already made from the template: the same step, by position.
            $made = $pdo->prepare(
                'SELECT s.id, s.inputs FROM document_route_steps s
                   JOIN document_routes r ON r.id = s.route_id AND r.tenant_id = s.tenant_id
                  WHERE s.tenant_id = :tenant_id AND r.template_id = :template_id AND s.position = :position'
            );
            $made->execute([':tenant_id' => $tenantId, ':template_id' => $templateId, ':position' => (int) $first['position']]);
            foreach ($made->fetchAll(PDO::FETCH_ASSOC) as $routeStep) {
                $routeInputs = self::withQuestions(self::decode($routeStep['inputs']), $questions);
                $pdo->prepare('UPDATE document_route_steps SET inputs = :inputs WHERE id = :id AND tenant_id = :tenant_id')
                    ->execute([':inputs' => json_encode($routeInputs, JSON_UNESCAPED_UNICODE), ':id' => (int) $routeStep['id'], ':tenant_id' => $tenantId]);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $inputs
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    private static function withQuestions(array $inputs, array $questions): array
    {
        $keys = array_column($inputs, 'key');
        foreach ($questions as $q) {
            if (count($inputs) >= self::MAX_INPUTS || in_array((string) $q['field_key'], $keys, true)) {
                continue;
            }
            $label = json_decode((string) $q['label'], true);
            $label = is_array($label) ? $label : ['en' => (string) $q['label']];
            $en = trim((string) ($label['en'] ?? reset($label) ?: $q['field_key']));
            $ar = trim((string) ($label['ar'] ?? ''));
            $inputs[] = [
                'key' => (string) $q['field_key'],
                'label' => mb_substr($en, 0, 80),
                'label_ar' => $ar !== '' ? mb_substr($ar, 0, 80) : null,
                'required' => in_array($q['is_required'], [true, 1, '1', 't', 'true'], true),
                'max_length' => 100,
                'type' => (string) $q['field_type'] === 'profile_ref' ? 'person' : 'text',
            ];
            $keys[] = (string) $q['field_key'];
        }

        return $inputs;
    }

    /** @return list<array<string, mixed>> */
    private static function decode(mixed $stored): array
    {
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : (is_array($stored) ? $stored : null);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private static function columnExists(PDO $pdo, string $driver, string $table, string $column): bool
    {
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            $stmt->closeCursor();

            return $found;
        }
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return false;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
