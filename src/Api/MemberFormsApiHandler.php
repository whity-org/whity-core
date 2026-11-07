<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Filling in a form an author opened to the workspace's members (migration 198).
 *
 * These routes carry no permission, like `/api/me/inbox`. Being an active member of
 * the workspace, on a form somebody with `forms:manage` opened, is the authorization,
 * and it is checked here because neither half is something a route table can say.
 * Holders of `forms:submit` keep the routes they have, for every published form.
 *
 * ONE REFUSAL. A form that doesn't exist, isn't published, isn't open, or a caller
 * who isn't a member all get the same 404, so nobody can use this to learn which
 * forms exist.
 *
 * THE QUESTIONS ARE THE ONES A STRANGER WOULD BE ASKED. A person or unit question is
 * left out, using {@see PublicFormView::answerableFields()}: an ordinary member can't
 * read the tenant's people or units (migration 101), so the picker would be empty and
 * the existence check behind it would be a directory, one id at a time. What is shown
 * and what is checked come from the same list.
 */
final class MemberFormsApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormRenderer $renderer,
        private readonly SubmissionIssuer $issuer,
        private readonly FormUploadsApiHandler $uploads,
    ) {
    }

    /**
     * GET /api/v1/me/forms — the forms this member may fill in, newest change first.
     * A caller who isn't a member, or has no profile, gets an empty list.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $profileId = self::actorProfileId($request);
            if ($profileId === null || !$this->isActiveMember($tenantId, $profileId)) {
                return Response::json(['data' => []]);
            }

            return Response::json(['data' => array_map(
                [self::class, 'view'],
                $this->forms->listOpenToMembers($tenantId),
            )]);
        } catch (\Exception $e) {
            error_log('[MemberFormsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch forms', 500);
        }
    }

    /**
     * GET /api/v1/me/forms/{id}/render — what drawing the form needs, with the
     * person's own prefill.
     *
     * @param array<string, string> $params
     */
    public function render(Request $request, array $params): Response
    {
        try {
            $form = $this->accessibleForm($request, $params);
            if ($form === null) {
                return self::refuse();
            }

            $rendered = $this->renderer->render((int) $form['tenant_id'], self::actorProfileId($request), $form);

            $fields = PublicFormView::answerableFields($rendered['fields']);
            $keys = array_flip(array_column($fields, 'field_key'));

            return Response::json(['data' => [
                'form' => self::view($form),
                'fields' => $fields,
                'sections' => FormRenderer::sectionsOf($fields, FormRenderer::sectionMeta($form)),
                'prefill' => array_intersect_key($rendered['prefill'], $keys),
                'unresolved_prefill' => array_values(array_filter(
                    $rendered['unresolved_prefill'],
                    static fn (array $entry): bool => isset($keys[$entry['field_key']]),
                )),
                'accepts_submissions' => $fields !== [],
            ]]);
        } catch (\Exception $e) {
            error_log('[MemberFormsApiHandler] render failed: ' . $e->getMessage());

            return Response::error('Failed to render form', 500);
        }
    }

    /**
     * POST /api/v1/me/forms/{id}/uploads — the ordinary upload, once this caller may
     * fill in this form. Its limits and its single-use claim apply unchanged.
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if ($this->accessibleForm($request, $params) === null) {
            return self::refuse();
        }

        return $this->uploads->upload($request, $params);
    }

    /**
     * POST /api/v1/me/forms/{id}/submissions — submit, under the caller's own name.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        try {
            $form = $this->accessibleForm($request, $params);
            if ($form === null) {
                return self::refuse();
            }

            $tenantId = (int) $form['tenant_id'];
            $fields = PublicFormView::answerableFields($this->fields->listForForm($tenantId, (int) $form['id']));
            if ($fields === []) {
                // Refused rather than recording an empty submission as a success.
                return Response::error('This form is not accepting submissions right now', 422);
            }

            $data = JsonBody::parsed($request)['data'] ?? [];
            if (!is_array($data)) {
                return Response::error('data must be an object of answers keyed by field key', 422);
            }

            /** @var array<string, mixed> $answers */
            $answers = [];
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $answers[$key] = $value;
                }
            }

            $result = $this->issuer->submit($tenantId, self::actorProfileId($request), $form, $fields, $answers);

            return Response::json([
                'data' => $result['submission'],
                'meta' => ['routed' => $result['routed'], 'ignored_keys' => $result['ignored']],
            ], 201);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 422, $e->details());
        } catch (\Exception $e) {
            error_log('[MemberFormsApiHandler] submit failed: ' . $e->getMessage());

            return Response::error('Failed to record the submission', 500);
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function accessibleForm(Request $request, array $params): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $profileId = self::actorProfileId($request);
        if ($tenantId === null || $profileId === null) {
            return null;
        }

        $form = $this->forms->find($tenantId, (int) ($params['id'] ?? 0));
        if (
            $form === null
            || ($form['open_to_members'] ?? false) !== true
            || !FormStatus::acceptsSubmissions((string) ($form['status'] ?? ''))
            || !$this->isActiveMember($tenantId, $profileId)
        ) {
            return null;
        }

        return $form;
    }

    private function isActiveMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = :status
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => 'active']);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * A form, rebuilt key by key as {@see PublicFormView::form()} does, so a column
     * added to `forms` next year isn't shown to every member until somebody adds it.
     * Who authored it, where submissions go and how many arrived are not theirs to see.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function view(array $form): array
    {
        return [
            'id' => (int) $form['id'],
            'name' => $form['name'] ?? [],
            'description' => $form['description'] ?? null,
            'description_i18n' => $form['description_i18n'] ?? new \stdClass(),
            'submit_label' => $form['submit_label'] ?? null,
            // The heading and picture written for the person filling it in (WHIT-944).
            'title' => $form['title'] ?? null,
            'banner' => $form['banner'] ?? null,
            'status' => (string) ($form['status'] ?? ''),
        ];
    }

    private static function refuse(): Response
    {
        return Response::error('Form not found', 404);
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
