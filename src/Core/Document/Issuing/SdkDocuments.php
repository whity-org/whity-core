<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Documents\DocumentNotIssuedException;
use Whity\Sdk\Documents\Documents;
use Whity\Sdk\Documents\IssueRequest;
use Whity\Sdk\Documents\IssueResult;

/**
 * The SDK's {@see Documents} (SDK 1.56, WHIT-742): a plugin issues through
 * the same {@see TemplateIssuing} as `POST /api/documents`, as the person
 * whose request it is in. The tenant and the person come from the host's own
 * request context, never from the plugin, so it can't issue for another
 * workspace or as somebody else.
 *
 * Outside a request there is no person. A job of a plugin that declares
 * `issuesInBackground()` then acts as that plugin (WHIT-742 slice 3,
 * DOCUMENT-AUTOMATION.md 3.7): from its own slots only, with only the
 * permissions it declares, where the workspace allowed it, recorded with no
 * person and the system as the audit actor. The plugin is the one the host
 * ran the job for, never one the plugin names. Anything else is `no_actor`.
 */
final class SdkDocuments implements Documents, HostWiredService
{
    /** @var TemplateIssuing|\Closure(): TemplateIssuing */
    private TemplateIssuing|\Closure $issuing;

    /** @var (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null */
    private ?\Closure $jobPlugin;

    /**
     * @param TemplateIssuing|\Closure(): TemplateIssuing $issuing
     * @param (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null $jobPlugin
     *        the plugin a running job belongs to, and what it declares; null
     *        where no jobs run (a request)
     */
    public function __construct(
        TemplateIssuing|\Closure $issuing,
        private readonly RoleChecker $roleChecker,
        ?\Closure $jobPlugin = null,
        private readonly ?TemplateSlotRegistry $slots = null,
    ) {
        $this->issuing = $issuing;
        $this->jobPlugin = $jobPlugin;
    }

    public function canIssue(): bool
    {
        return $this->actor() !== null;
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $actor = $this->actor();
        if ($actor === null) {
            throw new DocumentNotIssuedException(
                DocumentNotIssuedException::NO_ACTOR,
                'There is no person to issue this document for; plugins issue in a request, as the person making it',
            );
        }

        // A plugin in the background is the system on the audit trail, not
        // a blank actor; the document events say which plugin.
        $previous = AuditContext::getActor();
        if ($actor->plugin !== null) {
            AuditContext::setActor(Actor::system(), AuditContext::getIpAddress());
        }
        try {
            $issued = $this->issuing()->issue($actor, new IssueInput(
                slot: $request->template->slot,
                sourceId: $request->template->sourceId,
                record: $request->record,
                values: $request->values === [] ? null : $request->values,
                title: $request->title,
                originKey: $request->originKey,
                render: $request->render,
            ));
        } catch (DocumentIssueRefused $e) {
            throw new DocumentNotIssuedException($e->reason, $e->clientMessage, $e->details);
        } finally {
            if ($actor->plugin !== null) {
                AuditContext::setActor($previous, AuditContext::getIpAddress());
            }
        }

        $document = DocumentPresenter::document($issued['document'], $issued['artifacts']);

        return new IssueResult(
            (int) $document['id'],
            (string) $document['title'],
            $issued['replayed'],
            is_string($document['content_url'] ?? null) ? $document['content_url'] : null,
        );
    }

    private function actor(): ?ActingAs
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId === 0) {
            return null;
        }
        $profileId = AuditContext::getActorUserId();
        if ($profileId !== null) {
            return new ActingAs($tenantId, $profileId, ScopedPermissionSet::forProfile($this->roleChecker, $profileId, $tenantId));
        }

        // No person: a job of a plugin that declares it issues in the background.
        $job = AuditContext::getJob();
        $owner = $job !== null && $this->jobPlugin !== null ? ($this->jobPlugin)($job) : null;
        if ($owner === null || $this->slots === null || !$this->slots->declaresBackground($owner['plugin'])) {
            return null;
        }

        return ActingAs::background($tenantId, $owner['plugin'], $owner['permissions']);
    }

    private function issuing(): TemplateIssuing
    {
        if ($this->issuing instanceof \Closure) {
            $this->issuing = ($this->issuing)();
        }

        return $this->issuing;
    }
}
