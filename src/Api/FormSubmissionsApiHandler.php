<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Form\SubmissionProgress;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;
use Whity\Http\JsonBody;

/**
 * Submitting a form, and reading back what was submitted.
 *
 * THREE READ SURFACES, AND THE SPLIT IS AN AUTHORIZATION DECISION MADE IN THE
 * ROUTE TABLE RATHER THAN INSIDE A HANDLER
 * ---------------------------------------------------------------------------
 *   `GET /api/v1/form-submissions`         `forms:read`   — the tenant's
 *                                                            submissions.
 *   `GET /api/v1/form-submissions/{id}`    `forms:read`   — one of them.
 *   `GET /api/v1/me/form-submissions`      no permission  — ONLY the caller's own.
 *
 * The alternative — one endpoint gated on `forms:submit` that narrows itself to
 * the caller's own rows when they lack `forms:read` — would move the decision
 * INSIDE the handler, where it is a line of code that can be edited without
 * anybody reviewing an authorization change. Two routes put the rule in the route
 * table beside every other rule, where `RbacMiddleware` enforces it and the
 * route-catalogue gate lists it.
 *
 * `/me/…` needs no permission at all because the rows it returns already name
 * exactly one person — a tenant-wide permission has nothing left to decide, and a
 * member who filled in a form opened to them (migration 198) holds none. Same argument migration 113 makes about routing
 * ("being a recipient IS the authorization") and #978 about the inbox.
 *
 * THERE IS NO PATCH AND NO DELETE, AND ONE PUT
 * ---------------------------------------------
 * WHIT-920: `PUT /api/v1/me/form-submissions/{id}` is the author changing a
 * submission the route SENT BACK to them, which resubmits it and records a new
 * version the reviewers can compare. Nothing else edits one.
 *
 * A submission is what somebody declared, at a moment, under their own name, and
 * other people have already acted on it — the document it produced may be halfway
 * through an approval whose trail records approvals of a thing that would no
 * longer be what was approved. Somebody who got it wrong submits again; two
 * submissions with two timestamps is a true account, one edited submission is
 * not. See {@see FormSubmissionRepository}.
 */
final class FormSubmissionsApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormSubmissionRepository $submissions,
        private readonly SubmissionIssuer $issuer,
        private readonly FormUploadRepository $uploads,
        private readonly StorageDriverInterface $storage,
        // WHIT-920: where the caller's own submission is, for My submissions.
        private readonly ?SubmissionProgress $progress = null,
    ) {
    }

    /**
     * POST /api/v1/forms/{id}/submissions — submit a form.
     *
     * The answers arrive under `data`, keyed by `field_key`. Everything else in
     * the body is ignored: a submission is answers and nothing else, and a body
     * that could also set `form_version` or `submitted_by_profile_id` would let a
     * caller sign a declaration in somebody else's name.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $formId = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $formId);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $body = JsonBody::parsed($request);
            $data = $body['data'] ?? [];
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

            $result = $this->issuer->submit(
                $tenantId,
                self::actorProfileId($request),
                $form,
                FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, $formId)),
                $answers,
            );

            return Response::json([
                'data' => $result['submission'],
                'meta' => [
                    // Whether the submission actually started circulating. A form
                    // with no route template records the answers and stops there,
                    // which is a legitimate configuration — and a client that
                    // cannot tell the two apart would tell the submitter their
                    // request is "on its way" when nothing is moving.
                    'routed' => $result['routed'],
                    // Answer keys that matched no field on the form. Reported
                    // rather than swallowed: the realistic cause is a stale client
                    // whose author removed a field mid-session, and refusing the
                    // whole submission would throw away everything the person
                    // typed to punish them for a race they did not cause.
                    'ignored_keys' => $result['ignored'],
                ],
            ], 201);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186). The messages this
            // path produces name the person's OWN field label — "Contact number
            // is required" — because they are written for whoever is looking at
            // the form, not for its author.
            return Response::error($e->clientMessage, 422, $e->details());
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] submit failed: ' . $e->getMessage());

            return Response::error('Failed to record the submission', 500);
        }
    }

    /**
     * GET /api/v1/form-submissions — the tenant's submissions, newest first,
     * optionally narrowed to one form or one submitter.
     */
    public function list(Request $request): Response
    {
        return $this->listing($request, null);
    }

    /**
     * GET /api/v1/me/form-submissions — only the caller's own.
     *
     * A caller with no profile (a service principal) gets an EMPTY list rather
     * than a 403. It is a true answer — a principal that is not a person has made
     * no submissions — and it keeps an integration polling this endpoint from
     * looking like an authorization failure somebody has to investigate.
     */
    public function listMine(Request $request): Response
    {
        $profileId = self::actorProfileId($request);
        if ($profileId === null) {
            return Response::json(['data' => []]);
        }

        return $this->listing($request, $profileId);
    }

    /**
     * GET /api/v1/form-submissions/{id} — one submission, with the fields it was
     * answering.
     *
     * The fields travel with the submission because the two facts are useless
     * apart: `{"applicant_ref": 41}` means nothing without the field that says
     * what was asked. They are TODAY's fields — see
     * {@see \Whity\Core\Form\FormStatus} for exactly what `form_version` does and
     * does not promise about that — and the version is returned beside them so a
     * reader can see when the two do not line up.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0));
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $form = $this->forms->find($tenantId, (int) $submission['form_id']);
            $fields = $this->fields->listForForm($tenantId, (int) $submission['form_id']);

            return Response::json([
                'data' => $submission + [
                    'fields' => $fields,
                    'files' => self::publicFiles($this->filesOf($tenantId, (int) $submission['id'], $fields, $submission['data'] ?? null)),
                    // Stated rather than left for a client to compare: an answer
                    // set given against version 3 and read through version 5's
                    // fields is drift, not a bug, and a reader who cannot see the
                    // mismatch will report it as one.
                    'form_version_now' => $form === null ? null : (int) $form['version'],
                ],
            ]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the submission', 500);
        }
    }

    /**
     * GET /api/v1/me/form-submissions/{id} — one of the caller's own submissions.
     *
     * No permission, like {@see listMine()}: the route decides whose. Somebody else's is
     * the same 404 as one that doesn't exist. Only the questions they were asked, and
     * only their answers to those: what the office recorded has its own permission, and
     * the applicant has no route to it. A file answer says one was attached, because its
     * storage key means nothing to a reader and shouldn't leave the system.
     *
     * @param array<string, string> $params
     */
    public function showMine(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $profileId = self::actorProfileId($request);
            if ($tenantId === null || $profileId === null) {
                return Response::error('Submission not found', 404);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0), $profileId);
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $formId = (int) $submission['form_id'];
            $form = $this->forms->find($tenantId, $formId);
            $fields = FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, $formId));
            $asked = array_flip(array_map(static fn (array $field): string => (string) $field['field_key'], $fields));
            $data = array_intersect_key(is_array($submission['data'] ?? null) ? $submission['data'] : [], $asked);
            $files = self::publicFiles($this->filesOf($tenantId, (int) $submission['id'], $fields, $data));
            foreach ($fields as $field) {
                $key = (string) $field['field_key'];
                if ($field['field_type'] === FieldType::FILE && isset($data[$key])) {
                    $data[$key] = true;
                }
            }

            return Response::json([
                'data' => ['data' => (object) $data] + $submission + [
                    'fields' => $fields,
                    'files' => $files,
                    'form_version_now' => $form === null ? null : (int) $form['version'],
                    // WHIT-920: the stages and outcomes, the notes written for
                    // them, and whether they can edit and resubmit it now.
                    'progress' => $this->progress?->forSubmission($tenantId, $submission, $profileId),
                ],
            ]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] showMine failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the submission', 500);
        }
    }

    /**
     * PUT /api/v1/me/form-submissions/{id} — change a submission that was sent
     * back to the caller, and send it again (WHIT-920).
     *
     * Body: `{ "data": { ...answers }, "note": "optional" }`. The answers are
     * checked as a new submission's are. A file question left unanswered keeps
     * the file already attached, so the author only uploads what they replace.
     * Refused with reason `not_sent_back` unless the route is waiting for this
     * person's changes; somebody else's submission is the same 404 as one that
     * does not exist.
     *
     * @param array<string, string> $params
     */
    public function resubmitMine(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $profileId = self::actorProfileId($request);
            if ($tenantId === null || $profileId === null) {
                return Response::error('Submission not found', 404);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0), $profileId);
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $body = JsonBody::parsed($request);
            $data = $body['data'] ?? [];
            if (!is_array($data)) {
                return Response::error('data must be an object of answers keyed by field key', 422);
            }
            $note = $body['note'] ?? null;
            if ($note !== null && (!is_string($note) || mb_strlen($note) > 4000)) {
                return Response::error("'note' must be text of 4000 characters or fewer", 422);
            }

            $fields = FormFieldRepository::applicantFields(
                $this->fields->listForForm($tenantId, (int) $submission['form_id'])
            );
            $before = is_array($submission['data'] ?? null) ? $submission['data'] : [];

            /** @var array<string, mixed> $answers */
            $answers = [];
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $answers[$key] = $value;
                }
            }
            foreach ($fields as $field) {
                $key = (string) $field['field_key'];
                $given = $answers[$key] ?? null;
                if ($field['field_type'] === FieldType::FILE
                    && ($given === null || $given === '' || $given === [] || $given === true)
                    && isset($before[$key])) {
                    $answers[$key] = $before[$key];
                }
            }

            $result = $this->issuer->resubmit(
                $tenantId,
                $profileId,
                $submission,
                $fields,
                $answers,
                is_string($note) && trim($note) !== '' ? trim($note) : null,
            );

            return Response::json([
                'data' => $result['submission'],
                'meta' => ['ignored_keys' => $result['ignored']],
            ]);
        } catch (FormRejectedException $e) {
            $status = in_array($e->reason, ['not_sent_back', 'has_document'], true) ? 409 : 422;

            return Response::error($e->clientMessage, $status, $e->details());
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] resubmitMine failed: ' . $e->getMessage());

            return Response::error('Failed to send the submission again', 500);
        }
    }

    /**
     * One file on a submission, for a reviewer the route reached (WHIT-920).
     * The caller has already decided they may read the submission; this only
     * finds the file among the answers and sends it.
     */
    public function fileForReviewer(int $tenantId, int $submissionId, int $fileId): Response
    {
        try {
            $submission = $this->submissions->find($tenantId, $submissionId);
            if ($submission === null) {
                return Response::error('File not found', 404);
            }
            $fields = $this->fields->listForForm($tenantId, (int) $submission['form_id']);

            return $this->sendFile($this->filesOf($tenantId, $submissionId, $fields, $submission['data'] ?? null), $fileId);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] fileForReviewer failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the file', 500);
        }
    }

    /**
     * The questions and files of a submission, for a reviewer the route reached
     * (WHIT-920): every question, the applicant's and the office's, with the
     * files described rather than addressed by their storage key.
     *
     * @return array{fields: list<array<string, mixed>>, files: list<array<string, mixed>>, data: mixed}|null
     */
    public function contentForReviewer(int $tenantId, int $submissionId): ?array
    {
        $submission = $this->submissions->find($tenantId, $submissionId);
        if ($submission === null) {
            return null;
        }
        $fields = $this->fields->listForForm($tenantId, (int) $submission['form_id']);

        return [
            'fields' => $fields,
            'files' => self::publicFiles($this->filesOf($tenantId, $submissionId, $fields, $submission['data'] ?? null)),
            'data' => $submission['data'] ?? new \stdClass(),
        ];
    }

    /**
     * GET /api/v1/form-submissions/{id}/files/{fileId} — download a file somebody attached.
     *
     * `forms:read`, the same permission that reads the answer it belongs to.
     *
     * @param array<string, string> $params
     */
    public function fileContent(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('File not found', 404);
            }
            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0));
            if ($submission === null) {
                return Response::error('File not found', 404);
            }
            $fields = $this->fields->listForForm($tenantId, (int) $submission['form_id']);

            return $this->sendFile($this->filesOf($tenantId, (int) $submission['id'], $fields, $submission['data'] ?? null), (int) ($params['fileId'] ?? 0));
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] fileContent failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the file', 500);
        }
    }

    /**
     * GET /api/v1/me/form-submissions/{id}/files/{fileId} — download a file you attached.
     *
     * No permission, like {@see showMine()}: the route decides whose, and somebody else's
     * is the same 404 as one that doesn't exist. Only files answering a question the
     * person was asked.
     *
     * @param array<string, string> $params
     */
    public function myFileContent(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $profileId = self::actorProfileId($request);
            if ($tenantId === null || $profileId === null) {
                return Response::error('File not found', 404);
            }
            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0), $profileId);
            if ($submission === null) {
                return Response::error('File not found', 404);
            }
            $fields = FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, (int) $submission['form_id']));

            return $this->sendFile($this->filesOf($tenantId, (int) $submission['id'], $fields, $submission['data'] ?? null), (int) ($params['fileId'] ?? 0));
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] myFileContent failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the file', 500);
        }
    }

    /**
     * The files answering these questions on this submission.
     *
     * An upload has to be spent on THIS submission and named by an answer to one of
     * THESE questions. So the reader's list of questions decides what they can reach,
     * and an upload id is never a capability by itself.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array{id: int, field_key: string, storage_key: string, content_type: string, byte_size: int, filename: ?string}>
     */
    private function filesOf(int $tenantId, int $submissionId, array $fields, mixed $data): array
    {
        $answers = is_array($data) ? $data : [];
        $uploads = [];
        foreach ($this->uploads->forSubmission($tenantId, $submissionId) as $upload) {
            $uploads[$upload['storage_key']] = $upload;
        }

        $files = [];
        foreach ($fields as $field) {
            if (($field['field_type'] ?? null) !== FieldType::FILE) {
                continue;
            }
            $key = (string) $field['field_key'];
            $answer = $answers[$key] ?? null;
            foreach (is_array($answer) ? $answer : [$answer] as $reference) {
                if (!is_string($reference) || !isset($uploads[$reference])) {
                    continue;
                }
                $upload = $uploads[$reference];
                $files[] = [
                    'id' => $upload['id'],
                    'field_key' => $key,
                    'storage_key' => $upload['storage_key'],
                    'content_type' => $upload['content_type'],
                    'byte_size' => $upload['byte_size'],
                    'filename' => $upload['client_filename'],
                ];
            }
        }

        return $files;
    }

    /**
     * The same list without the storage key, which means nothing to a reader.
     *
     * @param list<array{id: int, field_key: string, storage_key: string, content_type: string, byte_size: int, filename: ?string}> $files
     * @return list<array{id: int, field_key: string, content_type: string, byte_size: int, filename: ?string}>
     */
    private static function publicFiles(array $files): array
    {
        return array_map(static function (array $file): array {
            unset($file['storage_key']);

            return $file;
        }, $files);
    }

    /**
     * One of the reader's files, always as an attachment.
     *
     * The bytes came from whoever filled in the form, possibly a visitor with no account,
     * and they're served from the application's own origin. `attachment` and `nosniff`
     * stop a browser running them as a page, and the sandbox policy is there for one that
     * ignores both. A storage failure is a 503 because the record is real and the bytes
     * were written, so "not found" would send the reader looking in the wrong place.
     *
     * @param list<array{id: int, field_key: string, storage_key: string, content_type: string, byte_size: int, filename: ?string}> $files
     */
    private function sendFile(array $files, int $fileId): Response
    {
        foreach ($files as $file) {
            if ($file['id'] !== $fileId) {
                continue;
            }
            try {
                $bytes = $this->storage->get($file['storage_key']);
            } catch (StorageException $e) {
                error_log('[FormSubmissionsApiHandler] reading an attached file failed: ' . $e->getMessage());

                return Response::error('This file is temporarily unavailable', 503);
            }

            return new Response(200, $bytes, [
                'Content-Type' => $file['content_type'],
                'Content-Disposition' => self::attachment($file['filename'], $file['content_type']),
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                // Somebody's papers, so no shared cache keeps them for the next caller.
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return Response::error('File not found', 404);
    }

    /** The header naming a download. The name is the sender's, so it can't be trusted in a header. */
    private static function attachment(?string $filename, string $contentType): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f"\\\\\/;]+/u', '_', (string) $filename));
        if ($name === '') {
            $name = 'attachment.' . FormUploadPolicy::extensionFor($contentType);
        }
        $plain = (string) preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name);

        return 'attachment; filename="' . $plain . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * The shared body of {@see list()} and {@see listMine()}.
     *
     * `$forcedProfileId` is not a filter the caller supplies — it is the route's
     * decision, and passing it here rather than reading it from the query string
     * is what makes `/me/…` unable to return anybody else's rows however the
     * request is spelled.
     */
    private function listing(Request $request, ?int $forcedProfileId): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $query = self::queryParams($request);

            $formId = self::intQuery($query, 'form_id');
            if ($formId !== null && $this->forms->find($tenantId, $formId) === null) {
                return Response::error('form_id does not name a form in this tenant', 422);
            }

            $profileId = $forcedProfileId ?? self::intQuery($query, 'submitted_by');

            return Response::json([
                'data' => $this->submissions->listForTenant(
                    $tenantId,
                    $formId,
                    $profileId,
                    self::intQuery($query, 'limit') ?? 50,
                    self::intQuery($query, 'offset') ?? 0,
                ),
            ]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch submissions', 500);
        }
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see TimeWindowsApiHandler} uses.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /**
     * @param array<string, string> $query
     */
    private static function intQuery(array $query, string $name): ?int
    {
        $raw = $query[$name] ?? null;

        return is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
