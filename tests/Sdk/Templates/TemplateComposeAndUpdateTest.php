<?php

declare(strict_types=1);

namespace Tests\Sdk\Templates;

use Tests\Support\ExampleClinicTemplates as Clinic;
use Whity\Sdk\Manifest\WorkspaceTemplateValidator;
use Whity\Sdk\Templates\MemoryLedger;
use Whity\Sdk\Templates\TemplateComposer;
use Whity\Sdk\Templates\TemplateEngine;
use Whity\Sdk\Testing\FakeWorkspace;
use Whity\Sdk\Testing\WorkspaceTemplateTestCase;

/**
 * WHIT-1413 (SDK 1.170, design section 40 §2.2 and §2.4): templates that
 * build on each other, extensions, part versions, and "Use the template's
 * version" per item (owner, 25 Nov 2026, Q3).
 */
final class TemplateComposeAndUpdateTest extends WorkspaceTemplateTestCase
{
    public function testTheKitChecksAChildAndAnExtension(): void
    {
        $this->assertExtends(Clinic::dental(), Clinic::clinic());
        $this->assertExtension(Clinic::patientFile(), Clinic::clinic());
    }

    public function testAChildMergesWithItsParentByKey(): void
    {
        $dental = $this->composed('dental', [Clinic::clinic(3), Clinic::dental()]);

        self::assertSame(['consultation', 'cleaning'], array_column($dental['services'], 'key'), 'check-up removed, cleaning added after');
        self::assertSame(['reception'], array_column($dental['roles'], 'key'), 'The parent\'s role carries over');
        self::assertSame(['first', 'cleaning'], array_column($dental['examples']['bookings'], 'key'));
        self::assertSame(['hours', 'services', 'chairs'], array_column($dental['setup']['steps'], 'id'));
        self::assertSame(['en' => 'Set up your clinic', 'ar' => 'إعداد عيادتك'], $dental['setup']['title'], 'The parent\'s title when the child has none');
        self::assertSame(['timezone' => 'Asia/Amman'], $dental['settings']);
        self::assertSame(['template:dental' => 1, 'extends:clinic' => 3], TemplateComposer::partVersions($dental));
        self::assertSame([], WorkspaceTemplateValidator::errors(Clinic::dental()), 'A child may use its parent\'s contacts');
    }

    public function testAChildWithoutItsParentOrInALoopIsLeftOut(): void
    {
        $alone = TemplateComposer::compose([Clinic::dental()]);
        self::assertSame([], $alone['templates']);
        self::assertStringContainsString('which is not offered here', $alone['notes'][0]);

        $a = ['extends' => 'b', 'key' => 'a'] + Clinic::clinic();
        $b = ['extends' => 'a', 'key' => 'b'] + Clinic::clinic();
        self::assertSame([], TemplateComposer::compose([$a, $b])['templates']);

        $c = ['extends' => 'dental', 'key' => 'kids-dental'] + Clinic::dental();
        $d = ['extends' => 'kids-dental', 'key' => 'braces'] + Clinic::dental();
        $deep = TemplateComposer::compose([Clinic::clinic(), Clinic::dental(), $c, $d]);
        self::assertSame(['clinic', 'dental', 'kids-dental'], array_column($deep['templates'], 'key'), 'At most three levels');
    }

    public function testHidesParentHidesTheParentsCard(): void
    {
        $dental = ['hidesParent' => true] + Clinic::dental();

        self::assertSame(['dental'], array_column(TemplateComposer::compose([Clinic::clinic(), $dental])['templates'], 'key'));
        self::assertSame(['hidesParent: only for a template that extends another'], array_map(
            static fn (string $e): string => substr($e, strlen('template.')),
            WorkspaceTemplateValidator::errors(['hidesParent' => true] + Clinic::clinic()),
        ));
    }

    public function testAnExtensionOnlyAddsAndReachesTemplatesBuiltOnItsTarget(): void
    {
        $taken = Clinic::patientFile();
        $taken['forms'][] = Clinic::clinic()['forms'][0];
        $result = TemplateComposer::compose([Clinic::clinic(), Clinic::dental()], [$taken + ['plugin' => 'Clinics']]);

        foreach ($result['templates'] as $template) {
            self::assertSame(['patient-intake', 'visit-notes'], array_column($template['forms'], 'key'), $template['key']);
            self::assertSame('Patient intake', $template['forms'][0]['name']['en'], 'The template\'s own form is never replaced');
            self::assertContains('extension:Clinics.patient-file', array_column(TemplateComposer::parts($template), 'key'));
        }
        self::assertStringContainsString('patient-intake left out', $result['notes'][0]);

        $broken = ['examples' => ['bookings' => [['key' => 'x', 'service' => 'nowhere', 'contact' => 'ibrahim', 'inDays' => 1, 'time' => '09:00']]]] + Clinic::patientFile();
        $kept = TemplateComposer::compose([Clinic::clinic()], [$broken + ['plugin' => 'Clinics']]);
        self::assertCount(1, $kept['templates'], 'A bad extension never costs the template');
        self::assertArrayNotHasKey('visit-notes', array_flip(array_column($kept['templates'][0]['forms'], 'key')));
        self::assertStringContainsString('extension Clinics.patient-file left out of clinic', $kept['notes'][0]);
    }

    public function testExtensionsAndChangesAreChecked(): void
    {
        self::assertNotSame([], WorkspaceTemplateValidator::extensionErrors(['settings' => ['timezone' => 'UTC']] + Clinic::patientFile()), 'An extension cannot change settings');
        self::assertNotSame([], WorkspaceTemplateValidator::extensionErrors(['target' => 'Clinic'] + Clinic::patientFile()));
        $clinic = Clinic::clinic(2);
        $clinic['changes'] = [['version' => 1, 'summary' => ['en' => 'a', 'ar' => 'ب']], ['version' => 2, 'summary' => ['en' => 'a', 'ar' => 'ب']]];
        self::assertContains('template.changes[1].version: newest first, each version once', WorkspaceTemplateValidator::errors($clinic));
        $clinic['changes'] = [['version' => 3, 'summary' => ['en' => 'a', 'ar' => 'ب']]];
        self::assertContains('template.changes[0].version: a version from 1 to the template\'s own', WorkspaceTemplateValidator::errors($clinic));
        self::assertContains('template.remove: only a template that extends another removes from it', WorkspaceTemplateValidator::errors(['remove' => ['services' => ['x']]] + Clinic::clinic()));
    }

    public function testAnUpdateOffersEachChangedItemAndChangesNothingOnItsOwn(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinic(1, '20.00'), $workspace, $ledger);
        $consultation = self::serviceNamed($workspace, 'Consultation');

        $update = $this->applyTemplate(Clinic::clinic(2, '25.00'), $workspace, $ledger, examples: false);

        $actions = self::actions($update['items']);
        self::assertSame(TemplateEngine::CHANGED, $actions['services:consultation'], 'Its price changed in the template');
        self::assertSame(TemplateEngine::DONE, $actions['services:check-up']);
        self::assertSame(TemplateEngine::DONE, $actions['forms:patient-intake']);
        self::assertSame(2000, $workspace->services[$consultation]['price_minor'], 'An update never changes it on its own');
        self::assertSame(['template:clinic' => 2], $ledger->parts(self::WORKSPACE, $update['run']));

        $adopted = $this->engine($workspace, $ledger)->adopt(self::WORKSPACE, self::PROFILE, Clinic::clinic(2, '25.00'), 'services', 'consultation');

        self::assertSame(['action' => TemplateEngine::ADD], $adopted);
        self::assertSame(2500, $workspace->services[$consultation]['price_minor']);
        self::assertSame(['action' => TemplateEngine::KEEP], $this->engine($workspace, $ledger)->adopt(self::WORKSPACE, self::PROFILE, Clinic::clinic(2, '25.00'), 'services', 'consultation'), 'Already the template\'s version');
        self::assertSame(TemplateEngine::DONE, self::actions($this->applyTemplate(Clinic::clinic(2, '25.00'), $workspace, $ledger, examples: false)['items'])['services:consultation']);
    }

    public function testUsingTheTemplatesFormKeepsThePersonsOwnQuestions(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinic(1), $workspace, $ledger);
        $formId = (int) array_key_first($workspace->forms);
        $workspace->call('POST', "/api/forms/{$formId}/fields", ['field_key' => 'their_own', 'field_type' => 'text', 'label' => ['en' => 'Theirs', 'ar' => 'لهم']]);
        $newer = Clinic::clinic(2);
        $newer['forms'][0]['fields'][0]['label'] = ['en' => 'Your full name', 'ar' => 'اسمك الكامل'];
        $newer['forms'][0]['fields'][] = ['key' => 'allergies', 'type' => 'textarea', 'label' => ['en' => 'Allergies', 'ar' => 'الحساسية']];

        self::assertSame(['action' => TemplateEngine::ADD], $this->engine($workspace, $ledger)->adopt(self::WORKSPACE, self::PROFILE, $newer, 'forms', 'patient-intake'));

        $fields = array_column($workspace->forms[$formId]['fields'], null, 'field_key');
        self::assertSame(['full_name', 'their_own', 'allergies'], array_keys($fields), 'Nothing deleted, the missing question added');
        self::assertSame('Your full name', $fields['full_name']['label']['en']);
    }

    public function testUsingTheTemplatesRoleOnlyAddsPermissionsThePersonHolds(): void
    {
        $workspace = new FakeWorkspace(permissions: ['calendar:read', 'calendar:write', 'contacts:read']);
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinic(1), $workspace, $ledger);
        $roleId = (int) array_key_first($workspace->roles);
        $workspace->roles[$roleId]['permissions'][] = 'forms:read';
        $newer = Clinic::clinic(2);
        $newer['roles'][0]['permissions'] = ['calendar:read', 'contacts:read', 'roles:delete'];

        $this->engine($workspace, $ledger)->adopt(self::WORKSPACE, self::PROFILE, $newer, 'roles', 'reception');

        self::assertSame(['calendar:read', 'calendar:write', 'forms:read', 'contacts:read'], $workspace->roles[$roleId]['permissions'], 'Added what it holds, removed nothing');
    }

    public function testAnItemThePersonDeletedIsNotChangedBack(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinic(1), $workspace, $ledger);
        unset($workspace->services[self::serviceNamed($workspace, 'Consultation')]);

        $update = $this->applyTemplate(Clinic::clinic(2, '25.00'), $workspace, $ledger, examples: false);
        $adopted = $this->engine($workspace, $ledger)->adopt(self::WORKSPACE, self::PROFILE, Clinic::clinic(2, '25.00'), 'services', 'consultation');

        self::assertSame(TemplateEngine::DONE, self::actions($update['items'])['services:consultation'], 'Gone, so nothing to offer');
        self::assertSame(TemplateEngine::SKIP, $adopted['action']);
        self::assertSame('It is no longer in your workspace.', $adopted['reason'] ?? null);
        self::assertCount(1, $workspace->services);
    }

    public function testAnUpdateAddsNoExamplesOnceTheyWereRemoved(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinic(1), $workspace, $ledger);
        $this->engine($workspace, $ledger)->removeExamples(self::WORKSPACE);
        $newer = Clinic::clinic(2);
        $newer['examples']['contacts'][] = ['key' => 'emad', 'name' => 'Emad Nasser', 'email' => 'emad@example.com'];

        $update = $this->applyTemplate($newer, $workspace, $ledger, examples: true);

        self::assertCount(1, $workspace->contacts);
        self::assertSame(TemplateEngine::KEEP, self::actions($update['items'])['examples:all']);
    }

    public function testItemsMadeBeforeFingerprintsTakeTodaysAtTheSameVersion(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $made = $this->applyTemplate(Clinic::clinic(1), $workspace, $ledger);
        $legacy = new MemoryLedger();
        $runId = $legacy->start(self::WORKSPACE, 'clinic', null, 1, self::PROFILE);
        $legacy->recordPart(self::WORKSPACE, $runId, 'template:clinic', null, 1);
        foreach ($ledger->items(self::WORKSPACE, $made['run']) as $kindKey => $item) {
            [$kind, $key] = explode(':', $kindKey, 2);
            $legacy->record(self::WORKSPACE, $runId, $kind, $key, $item['ref_id'], in_array($kind, ['contact', 'booking'], true));
        }

        $same = $this->applyTemplate(Clinic::clinic(1), $workspace, $legacy, examples: false);
        self::assertSame(TemplateEngine::DONE, self::actions($same['items'])['services:consultation']);
        self::assertNotNull($legacy->items(self::WORKSPACE, $runId)['service:consultation']['fingerprint'] ?? null, 'Took today\'s');

        $newer = $this->applyTemplate(Clinic::clinic(2, '20.00'), $workspace, $legacy, examples: false);
        self::assertSame(TemplateEngine::DONE, self::actions($newer['items'])['services:consultation'], 'Same entry in the new version: nothing to offer');
    }

    public function testADentalRunRecordsBothParts(): void
    {
        $dental = $this->composed('dental', [Clinic::clinic(4), Clinic::dental()]);
        $ledger = new MemoryLedger();

        $result = $this->applyTemplate($dental, null, $ledger);

        self::assertSame(['template:dental' => 1, 'extends:clinic' => 4], $ledger->parts(self::WORKSPACE, $result['run']));
    }

    private function engine(FakeWorkspace $workspace, MemoryLedger $ledger): TemplateEngine
    {
        return new TemplateEngine($workspace, $ledger, static fn (int $p, string $permission): bool => $workspace->holds($permission), fn (): \DateTimeImmutable => $this->now());
    }

    private static function serviceNamed(FakeWorkspace $workspace, string $name): int
    {
        foreach ($workspace->services as $id => $service) {
            if (($service['name']['en'] ?? null) === $name) {
                return $id;
            }
        }
        self::fail("No service {$name}");
    }

    /**
     * @param list<array{part: string, key: string, action: string}> $items
     * @return array<string, string>
     */
    private static function actions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['part'] . ':' . $item['key']] = $item['action'];
        }

        return $out;
    }
}
