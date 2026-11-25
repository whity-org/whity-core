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
 * WHIT-1419 (SDK 1.172, design section 40 §3.3): a template's pipelines are
 * review routes in Flows for its forms (owner, 25 Nov 2026, Q1: "Review
 * routes now").
 */
final class TemplatePipelinesTest extends WorkspaceTemplateTestCase
{
    public function testAPipelineIsValidAndNamesTheTemplatesOwnRolesAndForms(): void
    {
        $this->assertTemplateValid(Clinic::clinicWithReview());

        $wrong = Clinic::clinicWithReview();
        $wrong['pipelines'][0]['for'] = ['form' => 'no-such-form'];
        $wrong['pipelines'][0]['stages'][1]['role'] = 'surgeon';
        $wrong['pipelines'][0]['stages'][1]['permission'] = 'forms:review';
        self::assertSame([
            'template.pipelines[0].for.form: no-such-form is not one of the template\'s forms',
            'template.pipelines[0].stages[1].permission: unknown key',
            'template.pipelines[0].stages[1].role: surgeon is not one of the template\'s roles',
        ], WorkspaceTemplateValidator::errors($wrong));

        $empty = Clinic::clinicWithReview();
        $empty['pipelines'][0]['stages'] = [];
        self::assertContains('template.pipelines[0].stages: a list of 1 to ' . WorkspaceTemplateValidator::MAX_STAGES, WorkspaceTemplateValidator::errors($empty));
    }

    public function testApplyingMakesTheRouteAndSendsTheFormThroughIt(): void
    {
        $workspace = $this->workspace();
        $result = $this->applyTemplate(Clinic::clinicWithReview(), $workspace);

        self::assertSame(1, $result['made']['pipelines'] ?? null);
        self::assertContains(['part' => 'pipelines', 'key' => 'intake-review', 'action' => TemplateEngine::ADD], $result['items']);
        $route = array_values($workspace->routes)[0];
        self::assertSame('Intake review', $route['name']);
        $roleIds = array_column(array_values($workspace->roles), 'id', 'name');
        self::assertSame([
            ['position' => 1, 'rule_kind' => 'role', 'rule_config' => ['role_id' => $roleIds['Reception']], 'label' => 'Reception checks', 'decision' => true, 'allow_send_back' => true, 'canvas_x' => 0, 'canvas_y' => 0],
            ['position' => 2, 'rule_kind' => 'role', 'rule_config' => ['role_id' => $roleIds['Doctor']], 'label' => 'Doctor approves', 'decision' => true, 'allow_send_back' => false, 'canvas_x' => 0, 'canvas_y' => 160],
        ], $route['steps']);
        self::assertSame($route['id'], array_values($workspace->forms)[0]['route_template_id']);
        self::assertSame([], $result['skipped']);

        $this->assertIdempotent(Clinic::clinicWithReview());
    }

    public function testInArabicTheRouteAndItsStagesAreArabic(): void
    {
        $workspace = new FakeWorkspace(['billing.default_currency' => 'JOD', 'timezone' => 'Asia/Amman', 'locale' => 'ar']);
        $this->applyTemplate(Clinic::clinicWithReview(), $workspace);

        $route = array_values($workspace->routes)[0];
        self::assertSame('مراجعة الاستقبال', $route['name']);
        self::assertSame(['تدقيق الاستقبال', 'اعتماد الطبيب'], array_column($route['steps'], 'label'));
    }

    public function testWithoutLeaveToDesignRoutesThePipelineIsSkippedAndTheRestIsMade(): void
    {
        $workspace = new FakeWorkspace(permissions: ['calendar:read', 'calendar:write']);
        $result = $this->applyTemplate(Clinic::clinicWithReview(), $workspace);

        self::assertSame([], $workspace->routes);
        self::assertCount(1, $workspace->forms);
        self::assertContains([
            'part' => 'pipelines',
            'key' => 'intake-review',
            'action' => TemplateEngine::SKIP,
            'reason' => 'You may not design review routes, so this one was left out.',
        ], $result['items']);
    }

    public function testAFormThatAlreadyHasARouteKeepsIt(): void
    {
        $workspace = $this->workspace();
        $workspace->call('POST', '/api/document-route-templates', ['name' => 'Our own review']);
        $ownRoute = array_key_first($workspace->routes);
        $workspace->call('POST', '/api/forms', ['form_key' => 'patient-intake', 'name' => ['en' => 'Intake', 'ar' => 'استقبال']]);
        $formId = (int) array_key_last($workspace->forms);
        $workspace->call('PATCH', "/api/forms/{$formId}", ['route_template_id' => $ownRoute]);

        $result = $this->applyTemplate(Clinic::clinicWithReview(), $workspace);

        self::assertCount(2, $workspace->routes, 'The template\'s route is made beside the person\'s');
        self::assertSame($ownRoute, $workspace->forms[$formId]['route_template_id'], 'The form keeps the route the person chose');
        self::assertContains([
            'part' => 'pipelines',
            'key' => 'intake-review',
            'reason' => 'Its form already sends its answers through another review route, so that was not changed.',
        ], $result['skipped']);
    }

    public function testAChangedPipelineIsOfferedAndTheTemplatesVersionIsANewRoute(): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate(Clinic::clinicWithReview(1), $workspace, $ledger);
        $firstRoute = (int) array_key_first($workspace->routes);
        $workspace->routes[$firstRoute]['name'] = 'Intake review (ours)';

        $v2 = Clinic::clinicWithReview(2, 'A doctor signs it off');
        $result = $this->applyTemplate($v2, $workspace, $ledger, false);
        self::assertContains(['part' => 'pipelines', 'key' => 'intake-review', 'action' => TemplateEngine::CHANGED], $result['items']);
        self::assertCount(1, $workspace->routes, 'An update changes nothing the template made before');

        $engine = new TemplateEngine($workspace, $ledger, static fn (): bool => true, fn (): \DateTimeImmutable => $this->now());
        self::assertSame(['action' => TemplateEngine::ADD], $engine->adopt(self::WORKSPACE, self::PROFILE, $v2, 'pipelines', 'intake-review'));

        self::assertCount(2, $workspace->routes, 'The old route stays');
        self::assertSame('Intake review (ours)', $workspace->routes[$firstRoute]['name']);
        $new = $workspace->routes[(int) array_key_last($workspace->routes)];
        self::assertSame('Intake review, version 2', $new['name']);
        self::assertSame('A doctor signs it off', $new['steps'][1]['label']);
        self::assertSame($new['id'], array_values($workspace->forms)[0]['route_template_id'], 'The form moves to it, as it still used the template\'s route');
        self::assertSame(['action' => TemplateEngine::KEEP], $engine->adopt(self::WORKSPACE, self::PROFILE, $v2, 'pipelines', 'intake-review'));
    }

    public function testAChildRemovesAPipelineAndAnExtensionAddsOne(): void
    {
        $dental = ['remove' => ['services' => ['check-up'], 'pipelines' => ['intake-review']]] + Clinic::dental();
        $composed = $this->composed('dental', [Clinic::clinicWithReview(), $dental]);
        self::assertSame([], $composed['pipelines'] ?? []);

        $notesReview = [
            'key' => 'notes-review',
            'version' => 1,
            'target' => 'clinic',
            'forms' => Clinic::patientFile()['forms'],
            'pipelines' => [[
                'key' => 'notes-review',
                'name' => ['en' => 'Visit notes review', 'ar' => 'مراجعة ملاحظات الزيارة'],
                'for' => ['form' => 'visit-notes'],
                'stages' => [['key' => 'doctor', 'name' => ['en' => 'Doctor reads', 'ar' => 'يقرأ الطبيب'], 'role' => 'doctor']],
            ]],
        ];
        $this->assertExtension($notesReview, Clinic::clinicWithReview());
        $withExtension = $this->composed('clinic', [Clinic::clinicWithReview()], [$notesReview + ['plugin' => 'ClinicNotes']]);
        self::assertSame(['intake-review', 'notes-review'], array_column($withExtension['pipelines'], 'key'));

        $broken = $notesReview;
        $broken['pipelines'][0]['stages'][0]['role'] = 'surgeon';
        $result = TemplateComposer::compose([Clinic::clinicWithReview()], [$broken + ['plugin' => 'ClinicNotes']]);
        self::assertSame(['intake-review'], array_column($result['templates'][0]['pipelines'], 'key'), 'An extension that would break the template is left out whole');
        self::assertNotSame([], $result['notes']);
    }
}
