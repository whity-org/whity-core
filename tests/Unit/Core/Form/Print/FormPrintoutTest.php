<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Form\Print;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Theme\FormPrintStyle;
use Whity\Core\Form\Print\ApprovalCertificate;
use Whity\Core\Form\Print\FormPrintLabels;
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
            'section_key' => $section, 'visible_when' => null,
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
            // An "if yes" follow-up left empty does not stop the section collapsing.
            $f('drug_identify', 'textarea', 'Identify the drugs', 'regulated', ['is_required' => false]),
            $f('pi_sign', 'signature', 'Principal investigator', 'declaration'),
        ];
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, mixed> $answers
     * @param list<array<string, mixed>> $extraFields
     * @param array<string, mixed> $extra top-level inputs to add or replace
     * @return array<string, mixed>
     */
    private function build(array $style = [], array $answers = [], string $lang = 'en', bool $custom = true, array $extraFields = [], array $extra = []): array
    {
        return (new FormPrintout())->build($extra + [
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
                'fields' => [...self::fields(), ...$extraFields],
            ],
            'answers' => $answers + [
                'title' => 'Sleep and exams',
                'aims' => "This study asks whether sleep matters.\n\nWe will compare diaries.",
                'kind' => ['thesis', 'staff'],
                'drugs' => 'na', 'devices' => 'na', 'samples' => 'na',
                'pi_sign' => ['name' => 'Dr Ahmad Khalil', 'signed_at' => '2026-11-02T09:12:00Z', 'drawing' => ['viewBox' => [200, 60], 'paths' => ['M0 0 L10 10']]],
            ],
            // Merge map A3: what the office entered at a route step.
            'office' => [['label' => ['en' => 'Dean’s recommendation'], 'value' => 'Recommend approval', 'by' => 'Prof. Ahmad Odeh', 'at' => '2026-11-06 10:00:00']],
            'submission' => ['reference' => 'REC-EU 14/3/2026-27', 'submittedAt' => '2026-11-02 09:00:00', 'submittedBy' => 'Dr Ahmad Khalil'],
            'decision' => [
                'status' => 'approved', 'decidedAt' => '2026-11-12 10:14:00',
                'approvals' => [['name' => 'Prof. Amro Saleh', 'role' => 'Committee', 'at' => '2026-11-12 10:14:00']],
                'notCounted' => [['name' => 'Dr Emad Nasser', 'question' => 'Co-supervisor']],
            ],
            'verify' => ['url' => 'https://app.whity.dev/verify/abc', 'reference' => 'ABC-123'],
            'marks' => [['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'ISO 9001:2015']],
            'names' => static fn (string $type, int $id): ?string => $type === 'profile' ? [13 => 'Ahmad Khalil', 12 => 'Awss Haddad'][$id] ?? null : null,
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

    public function testAPersonColumnPrintsThePersonsNameInTheTable(): void
    {
        // WHIT-1162: the other researchers, a table with a person column.
        $researchers = [
            'field_key' => 'researchers', 'field_type' => 'repeating', 'label' => ['en' => 'Other researchers'],
            'help_text' => null, 'help_text_i18n' => [], 'is_required' => false, 'options' => [],
            'validation' => ['columns' => [
                ['key' => 'person', 'type' => 'profile_ref', 'label' => ['en' => 'Researcher']],
                ['key' => 'role', 'type' => 'text', 'label' => ['en' => 'Role']],
            ]],
            'section_key' => 'project', 'visible_when' => null,
        ];
        $payload = $this->build(answers: ['researchers' => [
            ['person' => 13, 'role' => 'Co-researcher'],
            ['person' => 12],
            ['person' => 99, 'role' => 'Left the workspace'],
        ]], extraFields: [$researchers]);

        $table = null;
        foreach (self::blocks($payload, 'formQuestion') as $q) {
            if ($q['text'] === 'Other researchers') {
                $table = $q['answer'];
            }
        }
        self::assertNotNull($table);
        self::assertSame('table', $table['kind']);
        self::assertSame(['Researcher', 'Role'], $table['columns']);
        self::assertSame([['Ahmad Khalil', 'Co-researcher'], ['Awss Haddad', ''], ['—', 'Left the workspace']], $table['rows']);
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

    public function testAStoredDrawingPrintsAsPathData(): void
    {
        $answer = ['name' => 'Ibrahim Mansour', 'signed_at' => '2026-11-02T11:05:00Z', 'drawing' => ['width' => 480, 'height' => 160, 'strokes' => [[[10, 40], [40, 10.5]], [[200, 80]]]]];
        $row = self::blocks($this->build([], ['pi_sign' => $answer]), 'signatureRow')[0];

        self::assertSame(['viewBox' => [480, 160], 'paths' => ['M10 40 L40 10.5', 'M200 80 l0.1 0']], $row['drawing']);
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

    /**
     * WHIT-1164: questions sharing a print group read as one; a checklist's
     * files fold into its rows, numbered through the printout; an optional
     * signature slot nobody signed is left out.
     */
    public function testPrintGroupsChecklistsAndEmptySignatureSlots(): void
    {
        $f = static fn (string $key, string $type, string $label, array $extra = []): array => $extra + [
            'field_key' => $key, 'field_type' => $type, 'label' => ['en' => $label], 'is_required' => true,
            'options' => [], 'validation' => [], 'section_key' => 's', 'visible_when' => null,
        ];
        $yn = [['value' => 'yes', 'label' => ['en' => 'Yes']], ['value' => 'no', 'label' => ['en' => 'No']], ['value' => 'na', 'label' => ['en' => 'N/A']]];
        $applicant = ['printGroup' => ['en' => 'Applicant']];
        $checklist = ['printGroup' => 'Attachments'];
        $fields = [
            $f('a_name', 'text', '1.3a Title and name', ['validation' => $applicant]),
            $f('a_email', 'text', '1.3b Email', ['validation' => $applicant]),
            $f('a_quals', 'textarea', '1.3c Qualifications', ['validation' => $applicant]),
            $f('d1', 'select', '9.1a Research proposal', ['options' => $yn, 'validation' => $checklist]),
            $f('d1_file', 'file', 'File', ['validation' => $checklist, 'visible_when' => ['field' => 'd1', 'equals' => 'yes']]),
            $f('d2', 'select', '9.1b Consent form', ['options' => $yn, 'validation' => $checklist]),
            $f('d2_file', 'file', 'File', ['validation' => $checklist, 'visible_when' => ['field' => 'd2', 'equals' => 'yes']]),
            $f('d3', 'select', '9.1c Budget', ['options' => $yn, 'validation' => $checklist]),
            $f('d3_file', 'file', 'File', ['validation' => $checklist, 'visible_when' => ['field' => 'd3', 'equals' => 'yes']]),
            $f('sign_1', 'signature', 'Applicant'),
            $f('sign_2', 'signature', 'Student 1', ['is_required' => false]),
        ];
        $out = (new FormPrintout())->build([
            'lang' => 'en', 'style' => FormPrintStyle::resolve(null, true), 'workspace' => ['name' => 'W'],
            'form' => ['title' => 'F', 'sections' => [['key' => 's', 'title' => ['en' => 'S'], 'description' => []]], 'fields' => $fields],
            'answers' => [
                'a_name' => 'Dr Ahmad Khalil', 'a_email' => 'a.khalil@example.edu', 'a_quals' => str_repeat('Eleven years of research. ', 12),
                'd1' => 'yes', 'd1_file' => ['k1', 'k2'], 'd2' => 'yes', 'd2_file' => 'k3', 'd3' => 'na',
                'sign_1' => ['name' => 'Dr Ahmad Khalil', 'signed_at' => '2026-11-16T09:00:00Z'],
            ],
            'submission' => [],
        ]);
        $questions = self::blocks($out, 'formQuestion');

        $group = $questions[0];
        self::assertSame(['1.3', 'Applicant', 'facts'], [$group['number'], $group['text'], $group['answer']['kind']]);
        self::assertSame([['label' => 'Title and name', 'value' => 'Dr Ahmad Khalil'], ['label' => 'Email', 'value' => 'a.khalil@example.edu']], $group['answer']['items']);
        self::assertSame('Qualifications', $questions[1]['text'], 'a long answer prints after the facts');
        self::assertSame('', $questions[1]['number']);

        $list = $questions[2];
        self::assertSame(['9.1', 'Attachments', 'yesNoRows'], [$list['number'], $list['text'], $list['answer']['kind']]);
        self::assertSame(['Yes · Attachment 1, Attachment 2', 'Yes · Attachment 3', 'N/A'], array_column($list['answer']['rows'], 'word'));

        self::assertSame(['Dr Ahmad Khalil'], array_column(self::blocks($out, 'signatureRow'), 'name'), 'the unsigned optional slot is left out');
    }

    /** WHIT-1164: a table's choice column prints its option's label. */
    public function testATableChoiceCellPrintsItsLabel(): void
    {
        $out = (new FormPrintout())->build([
            'lang' => 'en', 'style' => FormPrintStyle::resolve(null, true), 'workspace' => ['name' => 'W'],
            'form' => ['title' => 'F', 'fields' => [[
                'field_key' => 'students', 'field_type' => 'repeating', 'label' => '1.4 Students', 'is_required' => false, 'section_key' => null,
                'validation' => ['columns' => [
                    ['key' => 'name', 'label' => ['en' => 'Name'], 'kind' => 'text'],
                    ['key' => 'level', 'label' => ['en' => 'Program level'], 'kind' => 'select', 'options' => [['value' => 'master', 'label' => ['en' => 'Master']]]],
                ]],
            ]]],
            'answers' => ['students' => [['name' => 'Ibrahim Mansour', 'level' => 'master']]],
            'submission' => [],
        ]);
        self::assertSame([['Ibrahim Mansour', 'Master']], self::blocks($out, 'formQuestion')[0]['answer']['rows']);
    }

    /** WHIT-1164: "For official use" is one block per stage, and the stage's decision word leads. */
    public function testOfficialUseIsGroupedByStageAndTheDecisionWordIsTheStages(): void
    {
        $payload = (new FormPrintout())->build([
            'lang' => 'en', 'style' => FormPrintStyle::resolve(null, true), 'workspace' => ['name' => 'W'],
            'form' => ['title' => 'F', 'fields' => []], 'answers' => [], 'submission' => ['reference' => 'IRB-AAU 1/3/2026-2027'],
            'office' => [
                ['label' => ['en' => 'Recommendation'], 'value' => 'Recommend approval', 'by' => 'Prof. Awss Qasem', 'at' => '2026-11-18 10:00:00', 'stage' => 'Faculty committee'],
                ['label' => ['en' => 'Meeting number'], 'value' => '3', 'by' => 'Prof. Amro Saleh', 'at' => '2026-11-24 12:00:00', 'stage' => 'IRB'],
                ['label' => ['en' => 'IRB decision'], 'value' => 'Conditionally approved', 'by' => 'Prof. Amro Saleh', 'at' => '2026-11-24 12:00:00', 'stage' => 'IRB'],
            ],
            'decision' => ['status' => 'conditional', 'word' => 'Conditionally approved', 'decidedAt' => '2026-11-24 12:00:00', 'approvals' => []],
        ]);
        $office = array_values(array_filter(self::blocks($payload, 'formQuestion'), static fn (array $q): bool => in_array($q['text'], ['Faculty committee', 'IRB'], true)));
        self::assertSame(['Faculty committee', 'IRB'], array_column($office, 'text'));
        self::assertSame(['Meeting number', 'IRB decision', 'By', 'Date'], array_column($office[1]['answer']['items'], 'label'));
        $block = self::blocks($payload, 'decisionBlock')[0];
        self::assertSame(['conditional', 'Conditionally approved'], [$block['status'], $block['word']]);
        self::assertSame('Conditionally approved', self::blocks($payload, 'decisionLine')[0]['word']);
    }

    public function testADecisionChoiceMapsToAPrintedStatus(): void
    {
        self::assertSame('conditional', \Whity\Core\Form\Print\FormPrintoutService::statusOf('conditionally_approved', 'approved'));
        self::assertSame('revisions', \Whity\Core\Form\Print\FormPrintoutService::statusOf('revisions_required', 'approved'));
        self::assertSame('rejected', \Whity\Core\Form\Print\FormPrintoutService::statusOf('not_approved', 'approved'));
        self::assertSame('approved', \Whity\Core\Form\Print\FormPrintoutService::statusOf('approved', 'rejected'));
        self::assertSame('approved', \Whity\Core\Form\Print\FormPrintoutService::statusOf('noted', 'approved'));
        self::assertTrue(\Whity\Core\Form\Print\FormPrintoutService::isDecisionKey('irb_decision'));
        self::assertFalse(\Whity\Core\Form\Print\FormPrintoutService::isDecisionKey('decisions_count'));
    }

    /* ------------------------------------------------- approval certificate */

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>> the blocks before the cover's page break
     */
    private static function cover(array $payload): array
    {
        $out = [];
        foreach ($payload['content'] as $block) {
            if ($block['type'] === 'pageBreak') {
                return $out;
            }
            $out[] = $block;
        }
        self::fail('the certificate ends in a page break');
    }

    public function testAnApprovedFinalDocumentOpensOnACertificatePage(): void
    {
        $plain = $this->build();
        $payload = $this->build(extra: ['certificate' => []]);
        $cover = self::cover($payload);

        self::assertSame(1, $payload['coverPages'], 'one unnumbered page in front');
        self::assertSame(['letterhead', 'heading', 'paragraph', 'heading', 'decisionBlock', 'approvals'], array_column($cover, 'type'));
        self::assertSame('centred', $cover[0]['layout']);
        self::assertSame('Example University', $cover[0]['start']['name']);
        self::assertSame(['Amman · rec@example.edu'], $cover[0]['start']['lines']);
        self::assertSame('Certificate of approval', $cover[1]['text']);
        self::assertTrue($cover[1]['unnumbered'] && $cover[3]['unnumbered'], 'unnumbered headings');
        self::assertSame(
            'This is to certify that Example University has reviewed the application below and approved it. Scan the code to check that this certificate is genuine.',
            $cover[2]['text'],
        );
        self::assertSame('Sleep and exams', $cover[3]['text'], 'the project title answer, not the form name');
        self::assertSame(3, $cover[3]['level']);
        self::assertSame(['approved', 'Approved'], [$cover[4]['status'], $cover[4]['word']]);
        self::assertSame([
            ['label' => 'Reference', 'value' => 'REC-EU 14/3/2026-27', 'mono' => true],
            ['label' => 'Decided', 'value' => '12 Nov 2026'],
            ['label' => 'Conditions', 'value' => 'None'],
            ['label' => 'Submitted by', 'value' => 'Dr Ahmad Khalil'],
        ], $cover[4]['rows'], 'without options, the person who submitted');
        self::assertSame(['value' => 'https://app.whity.dev/verify/abc', 'caption' => 'Scan to verify', 'reference' => 'ABC-123'], $cover[4]['qr']);
        self::assertSame('Approvals', $cover[5]['title']);
        self::assertSame([['status' => 'approved', 'word' => 'Approved', 'name' => 'Prof. Amro Saleh', 'role' => 'Committee', 'at' => '12 Nov 2026, 10:14']], $cover[5]['rows']);

        // The application printout follows, unchanged: same blocks, header and footer.
        self::assertSame($plain['content'], array_slice($payload['content'], count($cover) + 1));
        self::assertSame($plain['header'], $payload['header']);
        self::assertSame($plain['footer'], $payload['footer']);
    }

    public function testTheCertificateFollowsTheRouteTemplatesOptions(): void
    {
        $f = static fn (string $key, string $type, array $extra = []): array => $extra + [
            'field_key' => $key, 'field_type' => $type, 'label' => ['en' => $key], 'help_text' => null, 'is_required' => false,
            'options' => [], 'validation' => [], 'section_key' => 'project', 'visible_when' => null,
        ];
        $payload = $this->build(
            answers: [
                'pi' => 'Dr Mohammad Ameen Al-Aghbar',
                'start' => '2026-08-01', 'end' => '2027-08-01',
                'students' => [
                    ['name' => 'Rahaf Riad Ja’ran', 'degree' => 'Master’s', 'id' => '202327051'],
                    ['name' => 'NA', 'degree' => 'NA', 'id' => 'NA'],
                ],
            ],
            extraFields: [
                $f('pi', 'text'), $f('start', 'date'), $f('end', 'date'), $f('empty', 'text'),
                $f('students', 'repeating', ['validation' => ['columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'degree', 'label' => 'Degree'], ['key' => 'id', 'label' => 'ID']]]]),
            ],
            extra: [
                'certificate' => ApprovalCertificate::config([
                    'title' => ['en' => 'Certificate of Ethics Approval', 'ar' => 'شهادة الموافقة الأخلاقية'],
                    'issuer' => 'the Institutional Review Board of Example University',
                    'rows' => [
                        ['label' => 'Applicant (principal investigator)', 'field' => 'pi'],
                        ['label' => 'Student researcher', 'field' => 'students'],
                        ['label' => 'Nothing answered', 'field' => 'empty'],
                        ['label' => 'Project period', 'fields' => ['start', 'end']],
                    ],
                ]),
                'decision' => [
                    'status' => 'conditional', 'word' => 'Conditionally approved', 'decidedAt' => '2026-11-18 09:30:00',
                    'conditions' => 'Report adverse events within 48 hours.',
                    'approvals' => [
                        ['name' => 'Amro Saleh', 'role' => 'Faculty Research Ethics Committee', 'at' => '2026-11-17 08:00:00', 'outcome' => 'Recommend approval'],
                        ['name' => 'Awss Haddad', 'role' => 'IRB decision', 'at' => '2026-11-18 09:30:00', 'outcome' => 'Conditionally approved'],
                    ],
                ],
            ],
        );
        $cover = self::cover($payload);

        self::assertSame('Certificate of Ethics Approval', $cover[1]['text']);
        self::assertStringStartsWith('This is to certify that the Institutional Review Board of Example University has reviewed the application below and approved it, subject to the conditions stated.', $cover[2]['text']);
        self::assertSame(['conditional', 'Conditionally approved'], [$cover[4]['status'], $cover[4]['word']]);
        self::assertSame([
            ['label' => 'Reference', 'value' => 'REC-EU 14/3/2026-27', 'mono' => true],
            ['label' => 'Decided', 'value' => '18 Nov 2026'],
            ['label' => 'Conditions', 'value' => 'Report adverse events within 48 hours.'],
            ['label' => 'Applicant (principal investigator)', 'value' => 'Dr Mohammad Ameen Al-Aghbar'],
            ['label' => 'Student researcher', 'value' => 'Rahaf Riad Ja’ran, Master’s, 202327051'],
            ['label' => 'Project period', 'value' => '1 Aug 2026 to 1 Aug 2027'],
        ], $cover[4]['rows'], 'an unanswered row and an N/A table row are left out');
        self::assertSame(['status' => 'approved', 'word' => 'Recommend approval', 'name' => 'Amro Saleh', 'role' => 'Faculty Research Ethics Committee', 'at' => '17 Nov 2026, 08:00'], $cover[5]['rows'][0]);
    }

    public function testTheCertificateInArabic(): void
    {
        $payload = $this->build(lang: 'ar', extra: ['certificate' => []]);
        $cover = self::cover($payload);

        self::assertSame('rtl', $payload['direction']);
        self::assertSame('جامعة المثال', $cover[0]['start']['name'], 'the workspace’s Arabic name');
        self::assertSame('شهادة موافقة', $cover[1]['text']);
        self::assertStringStartsWith('تشهد هذه الوثيقة بأنّ الطلب المبيّن أدناه قد رُوجع من قِبَل جامعة المثال وتمّت الموافقة عليه.', $cover[2]['text'], 'the same words for a university or a board');
        self::assertSame('تمت الموافقة', $cover[4]['word']);
        self::assertSame('الموافقات', $cover[5]['title']);
        self::assertSame('12 تشرين الثاني 2026، 10:14', $cover[5]['rows'][0]['at'], 'Western digits');
    }

    public function testNoCertificateWithoutTheOptionOrAnApproval(): void
    {
        $plain = $this->build();
        self::assertArrayNotHasKey('coverPages', $plain);
        self::assertSame('letterhead', $plain['content'][0]['type']);

        foreach (['waiting', 'revisions', 'rejected'] as $status) {
            $payload = $this->build(extra: ['certificate' => [], 'decision' => ['status' => $status, 'approvals' => []]]);
            self::assertArrayNotHasKey('coverPages', $payload, $status);
            self::assertNotContains('pageBreak', array_slice(array_column($payload['content'], 'type'), 0, 3), $status);
        }
    }

    public function testWithoutQrCodesTheCertificatePrintsNone(): void
    {
        $cover = self::cover($this->build(extra: ['certificate' => [], 'verify' => null]));

        self::assertArrayNotHasKey('qr', $cover[4]);
        self::assertStringNotContainsString('Scan', $cover[2]['text']);
    }

    public function testTheCertificateFitsItsPageWhateverWasTyped(): void
    {
        $approvals = [];
        for ($i = 1; $i <= 30; $i++) {
            $approvals[] = ['name' => str_repeat('Name ', 40), 'role' => 'Stage ' . $i, 'at' => '2026-11-12 10:14:00'];
        }
        $cover = self::cover($this->build(answers: ['title' => str_repeat('A very long project title ', 100)], extra: [
            'certificate' => [],
            'decision' => ['status' => 'approved', 'decidedAt' => '2026-11-12 10:14:00', 'conditions' => str_repeat('Condition. ', 200), 'approvals' => $approvals],
        ]));

        self::assertLessThanOrEqual(ApprovalCertificate::SUBJECT_MAX, mb_strlen($cover[3]['text']));
        self::assertStringEndsWith('… (In full in the decision on the last page.)', $cover[4]['rows'][2]['value'], 'long conditions point to the decision');
        self::assertSame(ApprovalCertificate::CONDITIONS_MAX, mb_strlen(explode(' (In full', $cover[4]['rows'][2]['value'])[0]));
        self::assertCount(ApprovalCertificate::APPROVALS_MAX, $cover[5]['rows']);
        self::assertSame('Stage 30', $cover[5]['rows'][ApprovalCertificate::APPROVALS_MAX - 1]['role'], 'the latest approvals');
        self::assertLessThanOrEqual(30, mb_strlen($cover[5]['rows'][0]['name']));
    }

    public function testMomentsPrintInTheWorkspacesTimeZone(): void
    {
        $payload = $this->build(extra: ['timezone' => 'Asia/Amman', 'certificate' => []]);

        // Stored in UTC; Amman is three hours ahead.
        self::assertSame('12 Nov 2026, 13:14', self::blocks($payload, 'approvals')[0]['rows'][0]['at']);
        self::assertSame('12 Nov 2026, 13:14', self::cover($payload)[5]['rows'][0]['at']);

        // A moment late in the UTC day is the next day in Amman.
        $amman = new FormPrintLabels('en', 'Asia/Amman');
        self::assertSame('13 Nov 2026', $amman->date('2026-11-12 22:30:00'));
        self::assertSame('2 Nov 2026, 12:12', $amman->date('2026-11-02T09:12:00Z', true));
        // A date question's answer is a day, not a moment: it never moves.
        self::assertSame('1 Aug 2026', (new FormPrintLabels('en', 'America/New_York'))->date('2026-08-01'));
        self::assertSame('12 Nov 2026, 10:14', (new FormPrintLabels('en', 'Not/AZone'))->date('2026-11-12 10:14:00', true), 'an unknown zone prints UTC');
    }
}
