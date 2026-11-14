<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Form\Print;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Theme\FormPrintStyle;
use Whity\Core\Form\Print\FormPrintout;

/**
 * Design 3.4 (FP.1-FP.10): a form response laid out with no template.
 */
final class FormPrintoutTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function fields(): array
    {
        $f = static fn (string $key, string $type, string $label, ?string $section, array $extra = []): array => $extra + [
            'field_key' => $key, 'field_type' => $type, 'label' => ['en' => $label, 'ar' => $label . ' (ar)'],
            'help_text' => null, 'help_text_i18n' => [], 'is_required' => true, 'options' => [], 'validation' => [],
            'section_key' => $section, 'visible_when' => null, 'audience' => 'applicant',
        ];
        $yn = [['value' => 'yes', 'label' => ['en' => 'Yes']], ['value' => 'no', 'label' => ['en' => 'No']], ['value' => 'na', 'label' => ['en' => 'N/A']]];

        return [
            $f('title', 'text', 'Project title', 'project'),
            $f('aims', 'textarea', 'Aims of the project', 'project', ['validation' => ['maxWords' => 300]]),
            $f('kind', 'multiselect', 'Type of project', 'project', ['options' => [
                ['value' => 'thesis', 'label' => ['en' => 'Thesis']],
                ['value' => 'staff', 'label' => ['en' => 'Staff research']],
                ['value' => 'funded', 'label' => ['en' => 'Funded project']],
            ]]),
            $f('reviewed', 'text', 'Has another committee reviewed it?', 'project', ['is_required' => false]),
            $f('drugs', 'select', 'Drugs or medicines', 'regulated', ['options' => $yn]),
            $f('devices', 'select', 'Medical devices', 'regulated', ['options' => $yn]),
            $f('samples', 'select', 'Human samples', 'regulated', ['options' => $yn]),
            $f('pi_sign', 'signature', 'Principal investigator', 'declaration'),
            $f('dean', 'select', 'Dean’s recommendation', null, ['audience' => 'staff', 'options' => [['value' => 'recommend', 'label' => ['en' => 'Recommend approval']]]]),
        ];
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function build(array $style = [], array $answers = [], string $lang = 'en', bool $custom = true): array
    {
        return (new FormPrintout())->build([
            'lang' => $lang,
            'style' => FormPrintStyle::resolve($style, $custom),
            'theme' => ['accent' => '#7a1f3d'],
            'workspace' => ['name' => 'Example University', 'lines' => ['Amman', 'rec@example.edu'], 'logo' => null, 'nameAr' => 'جامعة المثال'],
            'form' => [
                'title' => ['en' => 'Human Research Ethics Application', 'ar' => 'طلب أخلاقيات البحث'],
                'description' => ['en' => 'Research must not start without written approval.'],
                'sections' => [
                    ['key' => 'project', 'title' => ['en' => 'Project details'], 'description' => []],
                    ['key' => 'regulated', 'title' => ['en' => 'Regulated substances'], 'description' => []],
                    ['key' => 'declaration', 'title' => ['en' => 'Declaration and signatures'], 'description' => []],
                ],
                'fields' => self::fields(),
            ],
            'answers' => $answers + [
                'title' => 'Sleep and exams',
                'aims' => "This study asks whether sleep matters.\n\nWe will compare diaries.",
                'kind' => ['thesis', 'staff'],
                'drugs' => 'na', 'devices' => 'na', 'samples' => 'na',
                'pi_sign' => ['name' => 'Dr Ahmad Khalil', 'signed_at' => '2026-11-02T09:12:00Z', 'drawing' => ['viewBox' => [200, 60], 'paths' => ['M0 0 L10 10']]],
            ],
            'office' => ['dean' => ['value' => 'recommend', 'by' => 'Prof. Ahmad Odeh', 'at' => '2026-11-06 10:00:00']],
            'submission' => ['reference' => 'REC-EU 14/3/2026-27', 'submittedAt' => '2026-11-02 09:00:00', 'submittedBy' => 'Dr Ahmad Khalil'],
            'decision' => [
                'status' => 'approved', 'decidedAt' => '2026-11-12 10:14:00',
                'approvals' => [['name' => 'Prof. Amro Saleh', 'role' => 'Committee', 'at' => '2026-11-12 10:14:00']],
                'notCounted' => [['name' => 'Dr Emad Nasser', 'question' => 'Co-supervisor']],
            ],
            'verify' => ['url' => 'https://app.whity.dev/verify/abc', 'reference' => 'ABC-123'],
            'marks' => [['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'ISO 9001:2015']],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $payload, string $type): array
    {
        return array_values(array_filter($payload['content'], static fn (array $b): bool => $b['type'] === $type));
    }

    public function testTheStandardLayoutTopToBottom(): void
    {
        $payload = $this->build();
        $types = array_column($payload['content'], 'type');

        self::assertSame('letterhead', $types[0]);
        self::assertSame('formTitle', $types[1]);
        self::assertSame('decisionLine', $types[2], 'FP.5: the decision on top of page 1');
        self::assertSame('notice', $types[3]);
        self::assertSame('Submitted through Example University’s Whity by Dr Ahmad Khalil on 2 Nov 2026.', $payload['content'][1]['byline']);
        self::assertContains('decisionBlock', $types, 'and the full block at the end');
        self::assertSame('mark', self::blocks($payload, 'formSection')[0]['style'], 'FP.4: B by default');
        self::assertSame(['REC-EU 14/3/2026-27   Page {{page}} of {{pages}}'], [$payload['footer']['start']]);
        self::assertSame('ISO 9001:2015', $payload['footer']['end']['marks'][0]['image']['alt']);
        self::assertArrayNotHasKey('qr', $payload['footer']['end'], 'the small QR is an option');
        self::assertSame(2, $payload['header']['fromPage']);
    }

    public function testAnswersPrintAsTheCardsShow(): void
    {
        $payload = $this->build();
        $questions = self::blocks($payload, 'formQuestion');
        $byText = [];
        foreach ($questions as $q) {
            $byText[$q['text']] = $q;
        }

        $aims = $byText['Aims of the project'];
        self::assertSame(['This study asks whether sleep matters.', 'We will compare diaries.'], $aims['answer']['paragraphs']);
        self::assertSame('10 of 300 words', $aims['answer']['wordCount']);
        self::assertSame('continued', $aims['continued']);

        $kind = $byText['Type of project']['answer'];
        self::assertSame('choices', $kind['kind']);
        self::assertSame([true, true, false], array_column($kind['options'], 'chosen'), 'FP.3: every option, chosen ticked');

        $reviewed = $byText['Has another committee reviewed it?'];
        self::assertSame(['kind' => 'empty', 'text' => 'Not answered'], $reviewed['answer']);
        self::assertSame('Optional.', $reviewed['guidance']);
        self::assertSame('1.1', $byText['Project title']['number']);
    }

    public function testASectionAnsweredNaThroughoutIsOneLine(): void
    {
        $sections = self::blocks($this->build(), 'formSection');
        $regulated = array_values(array_filter($sections, static fn (array $s): bool => $s['title'] === 'Regulated substances'))[0];

        self::assertSame('Not applicable to this project', $regulated['notApplicable']['label']);
        self::assertStringContainsString('All 3 questions answered N/A', $regulated['notApplicable']['summary']);
        self::assertNotContains('Drugs or medicines', array_column(self::blocks($this->build(), 'formQuestion'), 'text'));

        $printAll = $this->build(['empty' => 'printAll']);
        $rows = array_values(array_filter(self::blocks($printAll, 'formQuestion'), static fn (array $q): bool => $q['answer']['kind'] === 'yesNoRows'));
        self::assertSame(['na', 'na', 'na'], array_column($rows[0]['answer']['rows'], 'value'), 'printed in full, as one Yes / No / N/A list');

        $hidden = $this->build(['empty' => 'hide']);
        self::assertNotContains('Regulated substances', array_column(self::blocks($hidden, 'formSection'), 'title'));
        self::assertNotContains('Has another committee reviewed it?', array_column(self::blocks($hidden, 'formQuestion'), 'text'));
    }

    public function testMoreThanEightOptionsPrintTheChosenOnly(): void
    {
        $out = (new FormPrintout())->build([
            'lang' => 'en', 'style' => FormPrintStyle::resolve(null, true),
            'workspace' => ['name' => 'W'],
            'form' => ['title' => 'F', 'fields' => [[
                'field_key' => 'many', 'field_type' => 'multiselect', 'label' => 'Many', 'is_required' => true, 'section_key' => null,
                'options' => array_map(static fn (int $i): array => ['value' => "o{$i}", 'label' => "Option {$i}"], range(1, 9)),
            ]]],
            'answers' => ['many' => ['o2']],
            'submission' => [],
        ]);
        $q = self::blocks($out, 'formQuestion')[0];
        self::assertSame([['text' => 'Option 2', 'chosen' => true]], $q['answer']['options']);
    }

    public function testSignaturesAreConfirmedInWhityBesideTheDrawing(): void
    {
        $row = self::blocks($this->build(), 'signatureRow')[0];

        self::assertSame('Dr Ahmad Khalil', $row['name']);
        self::assertSame('Confirmed in Whity · 2 Nov 2026, 09:12', $row['confirmed']);
        self::assertSame(['viewBox' => [200, 60], 'paths' => ['M0 0 L10 10']], $row['drawing']);
        self::assertSame('drawn', $row['style']);

        $none = self::blocks($this->build([], ['pi_sign' => ['name' => 'Awss Haddad', 'signed_at' => '2026-11-03T08:30:00Z']]), 'signatureRow')[0];
        self::assertSame('No drawn signature', $none['noDrawing']);
    }

    public function testTheDecisionApprovalsAndNotCounted(): void
    {
        $payload = $this->build();
        $block = self::blocks($payload, 'decisionBlock')[0];
        self::assertSame('approved', $block['status']);
        self::assertSame('https://app.whity.dev/verify/abc', $block['qr']['value']);
        self::assertContains(['label' => 'Conditions', 'value' => 'None'], $block['rows'], 'FP.5: always printed, even None');

        $approvals = self::blocks($payload, 'approvals')[0];
        self::assertSame('Prof. Amro Saleh', $approvals['rows'][0]['name']);
        self::assertSame('12 Nov 2026, 10:14', $approvals['rows'][0]['at']);
        self::assertStringStartsWith('Not counted: Dr Emad Nasser, named as Co-supervisor.', $approvals['notCounted']);

        $line = self::blocks($payload, 'decisionLine')[0];
        self::assertSame('Approved', $line['word']);
        self::assertSame('Full decision and approvals on the last page', $line['note']);
    }

    public function testTheOfficeAnswersPrintUnderForOfficialUse(): void
    {
        $payload = $this->build();
        $titles = array_column(self::blocks($payload, 'formSection'), 'title');
        self::assertContains('For official use', $titles);
        $dean = array_values(array_filter(self::blocks($payload, 'formQuestion'), static fn (array $q): bool => $q['text'] === 'Dean’s recommendation'))[0];
        self::assertSame([['label' => 'Answer', 'value' => 'Recommend approval'], ['label' => 'By', 'value' => 'Prof. Ahmad Odeh'], ['label' => 'Date', 'value' => '6 Nov 2026']], $dean['answer']['items']);
    }

    public function testAFreeWorkspacePrintsTheStandardLayoutWhateverItsThemeSays(): void
    {
        $payload = $this->build(['sectionStyle' => 'plane', 'decision' => 'end', 'questions' => 'twoColumns', 'header' => 'bilingual'], [], 'en', false);

        self::assertSame('mark', self::blocks($payload, 'formSection')[0]['style']);
        self::assertSame('decisionLine', $payload['content'][2]['type']);
        self::assertArrayNotHasKey('layout', self::blocks($payload, 'formQuestion')[0]);
        self::assertArrayNotHasKey('layout', $payload['content'][0]);
    }

    public function testAPaidWorkspaceChoosesEveryPart(): void
    {
        $payload = $this->build([
            'sectionStyle' => 'rail', 'decision' => 'end', 'officeDetails' => 'strip', 'questions' => 'twoColumns',
            'header' => 'bilingual', 'approvals' => 'cards', 'signatures' => 'withPaperLines',
            'footer' => ['qrEveryPage' => true, 'reference' => false],
        ]);

        self::assertSame(['current' => 1, 'total' => 3], self::blocks($payload, 'formSection')[0]['rail']);
        self::assertSame('officeStrip', $payload['content'][2]['type']);
        self::assertSame([], self::blocks($payload, 'decisionLine'), 'end only');
        self::assertSame('row', self::blocks($payload, 'formQuestion')[0]['layout']);
        self::assertSame('bilingual', $payload['content'][0]['layout']);
        self::assertSame('جامعة المثال', $payload['content'][0]['alt']['name']);
        self::assertSame('cards', self::blocks($payload, 'approvals')[0]['style']);
        self::assertSame('paper', self::blocks($payload, 'signatureRow')[0]['style']);
        self::assertSame('https://app.whity.dev/verify/abc', $payload['footer']['end']['qr']['value']);
        self::assertSame('Page {{page}} of {{pages}}', $payload['footer']['start']);
    }

    public function testArabicPrintsRightToLeftInArabicWords(): void
    {
        $payload = $this->build([], [], 'ar');

        self::assertSame('rtl', $payload['direction']);
        self::assertSame('طلب أخلاقيات البحث', $payload['content'][1]['title']);
        self::assertSame('تمت الموافقة', self::blocks($payload, 'decisionBlock')[0]['word']);
        self::assertSame('صفحة {{page}} من {{pages}}', substr($payload['footer']['start'], -strlen('صفحة {{page}} من {{pages}}')));
        self::assertStringContainsString('2026', self::blocks($payload, 'approvals')[0]['rows'][0]['at'], 'Western digits');
    }

    public function testCountsWordsInAnyScript(): void
    {
        self::assertSame(4, FormPrintout::words('Sleep isn’t optional, really.'));
        self::assertSame(3, FormPrintout::words('دراسة النوم والامتحانات'));
    }

    public function testALabelsOwnNumberIsUsedAndAnUntitledSectionReadsAsWords(): void
    {
        self::assertSame(['1.1', 'Project title'], FormPrintout::numbered('1.1 Project title'));
        self::assertSame(['9', 'Attached: Research Proposal Document'], FormPrintout::numbered('9. Attached: Research Proposal Document'));
        self::assertSame([null, '2026 budget'], FormPrintout::numbered('2026 budget'), 'a year is not a question number');
        self::assertSame([null, 'Project title'], FormPrintout::numbered('Project title'));

        $out = (new FormPrintout())->build([
            'lang' => 'en', 'style' => FormPrintStyle::resolve(null, true), 'workspace' => ['name' => 'W'],
            'form' => ['title' => 'F', 'sections' => [['key' => 'project_details', 'title' => [], 'description' => []]], 'fields' => [[
                'field_key' => 'title', 'field_type' => 'text', 'label' => ['en' => '1.1 Project title'], 'is_required' => true, 'section_key' => 'project_details',
            ]]],
            'answers' => ['title' => 'Sleep'], 'submission' => [],
        ]);
        self::assertSame('Project details', self::blocks($out, 'formSection')[0]['title']);
        $q = self::blocks($out, 'formQuestion')[0];
        self::assertSame(['1.1', 'Project title'], [$q['number'], $q['text']]);
    }
}
