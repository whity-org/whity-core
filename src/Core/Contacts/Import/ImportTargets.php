<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

/**
 * More places a spreadsheet column can go, beyond a contact's built-in
 * fields (WHIT-953). The workspace's own fields (WHIT-954) are one.
 *
 * Each target key must start with the provider's own prefix (such as
 * `field:`), so it can never shadow a built-in one.
 */
interface ImportTargets
{
    /**
     * The targets for this workspace: key => [label, names people give it].
     *
     * @return array<string, array{label: string, names: list<string>}>
     */
    public function targets(int $tenantId): array;

    /**
     * Checks and tidies one value. Returns the stored value, or a reason it
     * can't be used (shown on the skipped row).
     *
     * @return array{value: mixed}|array{error: string}
     */
    public function check(int $tenantId, string $target, string $raw): array;

    /**
     * The current values of these targets on a contact, to compare with and to
     * restore on undo.
     *
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    public function current(int $tenantId, int $contactId, array $targets): array;

    /**
     * Writes the values (target => checked value) on a contact. A null value
     * clears it.
     *
     * @param array<string, mixed> $values
     */
    public function write(int $tenantId, int $contactId, array $values): void;
}
