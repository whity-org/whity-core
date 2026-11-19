<?php

declare(strict_types=1);

namespace Whity\Http\Validation;

use Whity\Core\Response;

/**
 * One place that checks the fields of a request body (#2174).
 *
 * Before this, every handler wrote its own required, email and length checks,
 * so two handlers could disagree and a new one could forget a rule. A handler
 * now declares its fields and passes the parsed body through:
 *
 *     $checked = FieldValidator::check($body, [
 *         'email'        => Field::email()->required()->maxBytes(255),
 *         'display_name' => Field::text()->required()->max(120),
 *     ]);
 *     if (!$checked->ok()) {
 *         return $checked->refusal('Enter a valid email address', ['email' => 'invalid']);
 *     }
 *     $email = $checked->value('email');
 *
 * THE 422 STAYS WHAT CLIENTS ALREADY READ. {@see FieldCheck::refusal()} keeps
 * the handler's own `error` sentence and its own `details` keys, in front, and
 * adds three keys after them:
 *
 *  - `fields`: `{field: plain English}`, the shape the website contact form
 *    already returned;
 *  - `fields_ar`: the same in Arabic;
 *  - `field_codes`: `{field: code}`, see {@see Messages}.
 *
 * Every field with a problem is listed, not only the first, so a form can
 * mark them all at once.
 *
 * This is the first step: the handlers that take input from people who are not
 * signed in use it. The rest move over in later releases (#2174).
 */
final class FieldValidator
{
    /**
     * @param array<string, mixed> $body The parsed request body.
     * @param array<string, Field> $fields In the order the form shows them.
     */
    public static function check(array $body, array $fields): FieldCheck
    {
        $values = [];
        $problems = [];
        foreach ($fields as $name => $field) {
            $result = $field->check($name, $body);
            $values[$name] = $result['value'];
            if ($result['problem'] !== null) {
                [$en, $ar] = Messages::words(
                    $result['problem']['code'],
                    $result['problem']['params'],
                    $field->wordingFor($result['problem']['code'])
                );
                $problems[$name] = ['code' => $result['problem']['code'], 'en' => $en, 'ar' => $ar];
            }
        }

        return new FieldCheck($values, $problems);
    }

    /**
     * The shared 422 for problems a handler found itself (a domain not allowed,
     * a workspace name with no letters), so it reads like the validator's own.
     *
     * @param array<string, array{code: string, en: string, ar: string}> $problems
     * @param array<string, mixed> $details The handler's existing detail keys, kept first.
     */
    public static function refusal(string $message, array $problems, array $details = [], int $status = 422): Response
    {
        $fields = $fieldsAr = $codes = [];
        foreach ($problems as $name => $problem) {
            $fields[$name] = $problem['en'];
            $fieldsAr[$name] = $problem['ar'];
            $codes[$name] = $problem['code'];
        }

        return Response::error($message, $status, $details + [
            'fields' => $fields,
            'fields_ar' => $fieldsAr,
            'field_codes' => $codes,
        ]);
    }
}
