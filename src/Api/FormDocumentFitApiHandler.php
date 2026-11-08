<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionFieldProvider;
use Whity\Core\Form\FormTemplateDraft;
use Whity\Core\Form\FormTemplateFit;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Http\Response;

/**
 * A form against its final document template (see {@see FormTemplateFit}):
 *
 *   GET  /api/forms/{id}/document-fit                  what is missing, both ways
 *   POST /api/forms/{id}/document-fit/template-fields  put questions in the template's draft
 *   POST /api/forms/{id}/document-template             make a template from the form
 *
 * The route needs `forms:manage`. Anything that writes a template also needs
 * the permission the document editor would ask for, and a template the caller
 * can't see is reported as hidden so its fields aren't disclosed.
 *
 * A PUBLISHED TEMPLATE IS NOT CHANGED HERE. Questions go in its draft, which
 * prints nothing until a person publishes it from the editor. Publishing is
 * left to them because the draft can hold somebody's unfinished edits. Only a
 * template made here and now is published at once: nothing in it is unfinished,
 * and a final document can't be made from an unpublished template.
 */
final class FormDocumentFitApiHandler
{
    private const MAX_OWN_FIELDS = 50;
    private const OWN_KEY_MAX = 40;
    private const OWN_LABEL_MAX = 80;
    private const TITLE_MAX = 255;

    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly DocumentSourcesApiHandler $sourcesApi,
        private readonly DocumentPublishingApiHandler $publishing,
        private readonly DocumentFieldRegistry $registry,
        private readonly RoleChecker $roles,
        private readonly RouteTemplateRepository $routeTemplates,
    ) {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $context = $this->context($request, $params);
        if ($context instanceof Response) {
            return $context;
        }

        return Response::json(['data' => $this->report(...$context)]);
    }

    /**
     * Put questions in the draft of the form's template.
     *
     * @param array<string, string> $params
     */
    public function addToTemplate(Request $request, array $params): Response
    {
        $context = $this->context($request, $params);
        if ($context instanceof Response) {
            return $context;
        }
        [$tenantId, $callerId, $form] = $context;

        if (!$this->can($callerId, $tenantId, CorePermissions::DOCUMENTS_WRITE)) {
            return Response::error('Changing a template requires documents:write', 403);
        }
        $templateId = $form['final_template_id'] ?? null;
        $source = is_int($templateId) ? $this->sourcesApi->visibleRow($templateId, $tenantId, $callerId) : null;
        if ($source === null) {
            return Response::error('This form has no template you can change', 404);
        }

        $wanted = JsonBody::parsed($request)['field_keys'] ?? null;
        if (!is_array($wanted) || $wanted === [] || !array_is_list($wanted)) {
            return Response::error('field_keys must be a list of question keys', 422);
        }

        $questions = [];
        foreach (FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, (int) $form['id'])) as $field) {
            $questions[(string) $field['field_key']] = $field;
        }

        // A template written for this form can name its answers. Any other has to
        // carry them as its own fields, or publishing it would refuse a field its
        // record type doesn't offer.
        $forThisForm = ($source['record_type'] ?? null) === FormSubmissionFieldProvider::recordTypeFor((string) $form['form_key']);
        $blocks = is_array($source['blocks'] ?? null) ? array_values($source['blocks']) : [];
        /** @var list<array<string, string>> $own */
        $own = is_array($source['own_fields'] ?? null) ? array_values($source['own_fields']) : [];
        $ownKeys = array_column($own, 'key');
        $lang = (string) ($source['lang'] ?? 'en');

        foreach ($wanted as $key) {
            $question = is_string($key) ? ($questions[$key] ?? null) : null;
            if ($question === null) {
                return Response::error('field_keys must name questions people answer on this form', 422);
            }
            $label = FormTemplateDraft::label($question, $lang);
            if ($forThisForm) {
                $blocks[] = FormTemplateDraft::line($label, FormSubmissionFieldProvider::path($key));
                continue;
            }
            if (count($own) >= self::MAX_OWN_FIELDS) {
                return Response::error('This template has as many fields of its own as it can hold.', 422, ['code' => 'too_many_fields']);
            }
            $ownKey = self::ownKey($key, $ownKeys);
            $ownKeys[] = $ownKey;
            $own[] = ['key' => $ownKey, 'label' => mb_substr($label, 0, self::OWN_LABEL_MAX)];
            $blocks[] = FormTemplateDraft::line($label, 'template.' . $ownKey);
        }

        $changes = $forThisForm ? ['blocks' => $blocks] : ['blocks' => $blocks, 'own_fields' => $own];
        if ($this->sources->saveDraft((int) $source['id'], $tenantId, (int) $source['draft_version'], $changes, $callerId) === null) {
            return Response::error('The template was changed just now. Try again.', 409, ['code' => 'template_changed']);
        }

        return Response::json(['data' => $this->report($tenantId, $callerId, $form)]);
    }

    /**
     * Make a template from the form, publish it and link it.
     *
     * @param array<string, string> $params
     */
    public function createTemplate(Request $request, array $params): Response
    {
        $context = $this->context($request, $params);
        if ($context instanceof Response) {
            return $context;
        }
        [$tenantId, $callerId, $form] = $context;

        if (!$this->can($callerId, $tenantId, CorePermissions::DOCUMENTS_WRITE)
            || !$this->can($callerId, $tenantId, CorePermissions::DOCUMENTS_PUBLISH)) {
            return Response::error('Making a template for the workspace requires documents:write and documents:publish', 403);
        }

        $body = JsonBody::parsed($request);
        $lang = $body['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar'], true)) {
            return Response::error('lang must be en or ar', 422);
        }
        $title = $body['title'] ?? FormTemplateDraft::title($form, $lang);
        if (!is_string($title) || trim($title) === '' || mb_strlen($title) > self::TITLE_MAX) {
            return Response::error('title must be text of at most ' . self::TITLE_MAX . ' characters', 422);
        }

        $fields = $this->fields->listForForm($tenantId, (int) $form['id']);
        if (FormFieldRepository::applicantFields($fields) === []) {
            return Response::error('Add a question to the form first.', 422, ['code' => 'no_questions']);
        }

        $id = $this->sources->create($tenantId, [
            'kind' => 'template',
            'title' => trim($title),
            'lang' => $lang,
            'default_view' => 'page',
            'record_type' => FormSubmissionFieldProvider::recordTypeFor((string) $form['form_key']),
            'blocks' => FormTemplateDraft::blocks($form, $fields, $lang),
            'scope' => DocumentAccessPolicy::SCOPE_TENANT,
            'created_by' => $callerId,
        ]);
        $source = $this->sources->findById($id, $tenantId);
        if ($source === null || $this->publishing->publishChecked($tenantId, $callerId, $source) === null) {
            $this->sources->delete($id, $tenantId);

            return Response::error('The template could not be published.', 409, ['code' => 'not_publishable']);
        }

        $this->forms->update($tenantId, (int) $form['id'], ['final_template_id' => $id, 'document_left_out' => []]);

        return Response::json(['data' => ['id' => $id, 'title' => trim($title)]], 201);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>|null Null when the form has no template.
     */
    private function report(int $tenantId, int $callerId, array $form): ?array
    {
        $templateId = $form['final_template_id'] ?? null;
        if (!is_int($templateId)) {
            return null;
        }
        $source = $this->sourcesApi->visibleRow($templateId, $tenantId, $callerId);
        if ($source === null) {
            return ['hidden' => true];
        }

        $publishedVersion = $source['published_version'] ?? null;
        $printed = is_int($publishedVersion) ? $this->versions->find($templateId, $tenantId, $publishedVersion) : null;
        /** @var list<string> $leftOut */
        $leftOut = is_array($form['document_left_out'] ?? null) ? $form['document_left_out'] : [];
        $fit = FormTemplateFit::check(
            $this->fields->listForForm($tenantId, (int) $form['id']),
            $printed ?? $source,
            $printed !== null ? $source : null,
            $this->registry->paths($tenantId, null),
            $leftOut,
        );

        return $fit + [
            'hidden' => false,
            'template' => [
                'id' => $templateId,
                'title' => (string) $source['title'],
                'lang' => (string) $source['lang'],
                'published' => $printed !== null,
            ],
            'can_edit_template' => $this->can($callerId, $tenantId, CorePermissions::DOCUMENTS_WRITE),
            'route_uses_other_template' => $this->routeUsesOtherTemplate($tenantId, $form, $templateId),
        ];
    }

    /** @param array<string, mixed> $form */
    private function routeUsesOtherTemplate(int $tenantId, array $form, int $templateId): bool
    {
        $routeTemplateId = $form['route_template_id'] ?? null;
        if (!is_int($routeTemplateId)) {
            return false;
        }
        foreach ($this->routeTemplates->stepsFor($routeTemplateId, $tenantId) as $step) {
            if (is_int($step['final_template_id'] ?? null) && $step['final_template_id'] !== $templateId) {
                return true;
            }
        }

        return false;
    }

    private function can(int $callerId, int $tenantId, string $permission): bool
    {
        return ScopedPermissionSet::forProfile($this->roles, $callerId, $tenantId)($permission);
    }

    /** @param list<string> $taken */
    private static function ownKey(string $questionKey, array $taken): string
    {
        $base = substr($questionKey, 0, self::OWN_KEY_MAX);
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $suffix = '_' . $n;
            $key = substr($base, 0, self::OWN_KEY_MAX - strlen($suffix)) . $suffix;
        }

        return $key;
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function context(Request $request, array $params): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }
        $form = $this->forms->find($tenantId, (int) ($params['id'] ?? 0));
        if ($form === null) {
            return Response::error('Form not found', 404);
        }

        return [$tenantId, $callerId, $form];
    }
}
