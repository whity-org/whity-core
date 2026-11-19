<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Validation;

use PHPUnit\Framework\TestCase;
use Whity\Core\PasswordPolicy;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;
use Whity\Http\Validation\Messages;

/**
 * #2174: one field validator for request bodies. Valid input comes out exactly
 * as the hand-written checks left it; every problem is named, in English and
 * Arabic, with a code; and the 422 keeps the handler's own sentence and detail
 * keys in front of the new ones.
 */
final class FieldValidatorTest extends TestCase
{
    public function testValidInputIsCleanedTheWayTheHandlersDid(): void
    {
        $checked = FieldValidator::check(
            ['email' => '  Amro@Example.COM ', 'name' => '  Amro Saleh ', 'password' => ' keeps spaces ', 'phone' => ' +962 79 000 0000 '],
            [
                'email' => Field::email()->required(),
                'name' => Field::text()->required()->max(120),
                'password' => Field::password()->required(),
                'phone' => Field::phone(),
            ]
        );

        self::assertTrue($checked->ok());
        self::assertSame('amro@example.com', $checked->value('email'));
        self::assertSame('Amro Saleh', $checked->value('name'));
        self::assertSame(' keeps spaces ', $checked->value('password'), 'a password is never trimmed');
        self::assertSame('+962 79 000 0000', $checked->value('phone'));
    }

    public function testEveryProblemIsNamedInOrderWithCodesAndBothLanguages(): void
    {
        $checked = FieldValidator::check(
            ['email' => 'not-an-email', 'name' => str_repeat('a', 121), 'password' => 'short'],
            [
                'name' => Field::text()->required()->max(120),
                'email' => Field::email()->required(),
                'phone' => Field::phone(),
                'password' => Field::password()->required(),
                'missing' => Field::text()->required(),
            ]
        );

        self::assertFalse($checked->ok());
        self::assertSame('name', $checked->first(), 'the first problem is the first field declared');
        self::assertSame(
            ['name' => Messages::TOO_LONG, 'email' => Messages::INVALID_EMAIL, 'password' => Messages::TOO_SHORT, 'missing' => Messages::REQUIRED],
            array_map(static fn (array $p): string => $p['code'], $checked->problems())
        );
        self::assertFalse($checked->has('phone'), 'an optional empty field is fine');
        self::assertStringContainsString('120', $checked->problems()['name']['en']);
        self::assertStringContainsString('120', $checked->problems()['name']['ar'], 'Western digits in Arabic too');
        self::assertStringContainsString((string) PasswordPolicy::MIN_LENGTH, $checked->problems()['password']['en']);
        foreach ($checked->problems() as $problem) {
            self::assertNotSame('', $problem['ar']);
            self::assertNotSame($problem['en'], $problem['ar']);
        }
    }

    public function testAValueThatIsNotTextIsMissingNotArray(): void
    {
        $checked = FieldValidator::check(
            ['email' => ['x@example.com'], 'name' => 42],
            ['email' => Field::email()->required(), 'name' => Field::text()->required()]
        );

        self::assertSame(Messages::REQUIRED, $checked->code('email'));
        self::assertSame('42', $checked->value('name'), 'a number is read as its digits');
    }

    public function testLengthsInCharactersOrBytesAndTruncation(): void
    {
        $arabic = str_repeat('ب', 100); // 100 characters, 200 bytes
        $checked = FieldValidator::check(
            ['chars' => $arabic, 'bytes' => $arabic, 'cut' => '  ' . str_repeat('x', 10)],
            [
                'chars' => Field::text()->max(100),
                'bytes' => Field::text()->maxBytes(150),
                'cut' => Field::text()->truncate(4),
            ]
        );

        self::assertFalse($checked->has('chars'));
        self::assertSame(Messages::TOO_LONG, $checked->code('bytes'));
        self::assertSame('xxxx', $checked->value('cut'));
    }

    public function testAlternativeKeysEmailCaseAndOwnWording(): void
    {
        $checked = FieldValidator::check(
            ['tenantName' => 'Example Bakery', 'email' => 'Amro@Example.com', 'contact' => 'nope'],
            [
                'tenant_name' => Field::text()->from('tenant_name', 'tenantName')->required(),
                'email' => Field::email(lowercase: false),
                'contact' => Field::emailOrPhone()->say(Messages::INVALID_CONTACT, 'Write an email or a phone.', 'اكتب بريدًا أو هاتفًا.'),
            ]
        );

        self::assertSame('Example Bakery', $checked->value('tenant_name'));
        self::assertSame('Amro@Example.com', $checked->value('email'));
        self::assertSame(['code' => Messages::INVALID_CONTACT, 'en' => 'Write an email or a phone.', 'ar' => 'اكتب بريدًا أو هاتفًا.'], $checked->problems()['contact']);
    }

    public function testTheRefusalKeepsTheHandlersSentenceAndKeysFirst(): void
    {
        $checked = FieldValidator::check(['email' => 'bad'], ['email' => Field::email()->required(), 'name' => Field::text()->required()]);
        $response = $checked->refusal('Enter a valid email address', ['email' => 'invalid']);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertSame('Enter a valid email address', $body['error']);
        self::assertSame(['email', 'fields', 'fields_ar', 'field_codes'], array_keys($body['details']), 'the handler\'s own key stays first');
        self::assertSame('invalid', $body['details']['email']);
        self::assertSame(['email', 'name'], array_keys($body['details']['fields']));
        self::assertSame(['email' => Messages::INVALID_EMAIL, 'name' => Messages::REQUIRED], $body['details']['field_codes']);
        self::assertSame(array_keys($body['details']['fields']), array_keys($body['details']['fields_ar']));
    }

    public function testEveryCodeHasWordsInBothLanguages(): void
    {
        foreach (Messages::codes() as $code) {
            [$en, $ar] = Messages::words($code, ['max' => 5, 'min' => 8]);
            self::assertNotSame('', $en, $code);
            self::assertMatchesRegularExpression('/\p{Arabic}/u', $ar, $code);
            self::assertStringNotContainsString('{', $en . $ar, $code);
        }
    }
}
