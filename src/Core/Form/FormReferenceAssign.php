<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * When a form's references are given (WHIT-1017, `forms.reference_assign`).
 *
 * `submit` is what every form did before: the reference exists the moment the
 * form is sent. `approval` waits until the submission's route finishes
 * approved, so the reference can print a value a reviewer entered on the way
 * ({@see FormReferenceFormat} `{STEP:key}`), and a submission that is not
 * approved never takes a number.
 */
final class FormReferenceAssign
{
    public const SUBMIT = 'submit';

    public const APPROVAL = 'approval';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SUBMIT, self::APPROVAL];
    }

    /**
     * @throws FormRejectedException
     */
    public static function parse(mixed $raw): string
    {
        if (!is_string($raw) || !in_array($raw, self::all(), true)) {
            throw new FormRejectedException("reference_assign must be 'submit' or 'approval'");
        }

        return $raw;
    }
}
