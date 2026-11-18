<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Form\Print;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Form\Print\ApprovalCertificate;

/**
 * The approval certificate's options, set on a route template's final
 * document stage (`rule_config.certificate`).
 */
final class ApprovalCertificateTest extends TestCase
{
    public function testEveryOptionHasADefault(): void
    {
        self::assertSame([], ApprovalCertificate::config(null));
        self::assertSame([], ApprovalCertificate::config([]));
    }

    public function testATitleInBothLanguagesTheSubjectAndThePeople(): void
    {
        self::assertSame([
            'title' => ['en' => 'Certificate of Ethics Approval', 'ar' => 'شهادة الموافقة الأخلاقية'],
            'issuer' => ['en' => 'the Institutional Review Board'],
            'subject' => 'title',
            'rows' => [
                ['label' => ['en' => 'Applicant'], 'fields' => ['applicant_person']],
                ['label' => ['en' => 'Project period', 'ar' => 'مدة المشروع'], 'fields' => ['start', 'end']],
            ],
        ], ApprovalCertificate::config([
            'title' => ['en' => ' Certificate of Ethics Approval ', 'ar' => 'شهادة الموافقة الأخلاقية'],
            'issuer' => 'the Institutional Review Board',
            'subject' => 'title',
            'rows' => [
                ['label' => 'Applicant', 'field' => 'applicant_person'],
                ['label' => ['en' => 'Project period', 'ar' => 'مدة المشروع'], 'fields' => ['start', 'end']],
            ],
        ]));
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function refused(): iterable
    {
        yield 'a list' => [['a'], 'must be an object'];
        yield 'an unknown option' => [['colour' => 'gold'], "no option 'colour'"];
        yield 'an empty title' => [['title' => ['en' => ' ']], 'must not be empty'];
        yield 'a title in another language' => [['title' => ['fr' => 'Certificat']], '{en, ar}'];
        yield 'a title too long to fit' => [['title' => str_repeat('a', ApprovalCertificate::TITLE_MAX + 1)], 'characters or fewer'];
        yield 'a subject that is no key' => [['subject' => 'Project title!'], "question's key"];
        yield 'too many rows' => [['rows' => array_fill(0, ApprovalCertificate::ROWS_MAX + 1, ['label' => 'x', 'field' => 'x'])], 'up to 4 rows'];
        yield 'a row naming no question' => [['rows' => [['label' => 'Applicant']]], 'must name one question'];
        yield 'a row naming three' => [['rows' => [['label' => 'Period', 'fields' => ['a', 'b', 'c']]]], 'must name one question'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatCannotPrint(mixed $config, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ApprovalCertificate::config($config);
    }
}
