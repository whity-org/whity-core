<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Discovery;

use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolSurface;

/**
 * What `init_session` tells a model about the session it is in (WHIT-794):
 * who it acts as, what it can reach, what needs a person's approval, how to
 * work here, and its limits.
 *
 * BUILT FROM THE CALLER'S OWN LIST. The areas and counts come from the tools
 * {@see \Whity\Mcp\Tools\ToolsListHandler::listFor()} returns for this caller,
 * the same filter tools/list and search apply (exposure, token toolsets and
 * permissions, RBAC, an agent's grant), so the briefing can never claim more
 * than the caller can do. What the caller can't reach is counted at most,
 * never listed.
 *
 * Kept small (well under 1.5k tokens): it is read at the start of every
 * session. English or Arabic, by the `language` argument.
 */
final class SessionBriefing
{
    private const AGENT_KIND = 'ai-agent';
    private const AGENT_MODE = 'dev.whity/agentMode';

    /**
     * @param \Closure(int, int): array{name: ?string, workspace: ?string} $identity
     *        The caller's display name and workspace name, by profile and tenant.
     * @param array{perMinute: int, tenantPerMinute: int} $limits
     * @param \Closure(string, int): ?array<string, mixed> $persona
     *        A workspace agent's definition (AgentRepository's shape) by the
     *        agent's principal id and tenant, or null when it is not a
     *        workspace agent (WHIT-817).
     */
    public function __construct(
        private readonly ?\Closure $identity = null,
        private readonly array $limits = ['perMinute' => 60, 'tenantPerMinute' => 300],
        private readonly ?\Closure $persona = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $tools the caller's visible tools, from listFor()
     * @return array<string, mixed>
     */
    public function brief(McpPrincipal $principal, array $tools, string $language): array
    {
        $ar      = $language === 'ar';
        $isAgent = $principal->principalKind === self::AGENT_KIND;
        $who     = $this->identity !== null ? ($this->identity)($principal->userId, $principal->tenantId) : ['name' => null, 'workspace' => null];

        $areas = [];
        foreach ($tools as $tool) {
            $meta    = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
            $toolset = (string) ($meta[ToolSurface::META_TOOLSET] ?? '');
            $areas[$toolset] ??= ['operations' => 0, 'allowed' => 0, 'askFirst' => 0];
            $areas[$toolset]['operations']++;
            if ($isAgent) {
                $mode = $meta[self::AGENT_MODE] ?? 'ask';
                $areas[$toolset][$mode === 'allow' ? 'allowed' : 'askFirst']++;
            }
        }
        ksort($areas);

        $reach = [];
        foreach ($areas as $id => $counts) {
            $core  = CoreToolsets::TOOLSETS[$id] ?? null;
            $entry = ['id' => $id, 'title' => $core !== null ? ($ar ? $core['titleAr'] : $core['title']) : $id] + ['operations' => $counts['operations']];
            if ($isAgent) {
                $entry['allowed']  = $counts['allowed'];
                $entry['askFirst'] = $counts['askFirst'];
            }
            $reach[] = $entry;
        }

        $scope = McpScope::of($principal);

        $briefing = [
            'you' => array_filter([
                'actingAs'  => $isAgent
                    ? ($ar ? 'وكيل يعمل نيابة عن ' : 'an agent acting for ') . ($who['name'] ?? ($ar ? 'الشخص الذي منحك الصلاحية' : 'the person who granted you'))
                    : ($who['name'] ?? ($ar ? 'الشخص صاحب هذا الاتصال' : 'the person who owns this connection')),
                'workspace' => $who['workspace'] ?? null,
                'limitedTo' => $scope->toolsetEntries() !== [] ? $scope->toolsetEntries() : null,
            ], static fn (mixed $v): bool => $v !== null),
            'canReach' => $reach,
            'howToWork' => $ar ? [
                'ابحث بـ search_operations عمّا تريد فعله؛ البحث الفارغ يعرض المجالات.',
                'اطلب العملية كاملة بـ search_operations مع operationId لترى معاملاتها، ثم نفّذها بـ call_operation.',
                'لا ترى إلا ما تملك صلاحيته؛ ما لا يظهر في البحث غير متاح لك.',
            ] : [
                'Find what you need with search_operations; an empty query lists the areas.',
                'Get one operation in full with search_operations and its operationId, then run it with call_operation.',
                'You only see what you may use: what search doesn\'t find is not available to you.',
            ],
            'limits' => [
                'callsPerMinute'          => $this->limits['perMinute'],
                'workspaceCallsPerMinute' => $this->limits['tenantPerMinute'],
            ],
        ];

        if ($isAgent) {
            $off = 0;
            foreach ($principal->scope as $entry) {
                if (is_string($entry) && str_starts_with($entry, 'grant:') && str_ends_with($entry, '=off')) {
                    $off++;
                }
            }
            $briefing['askFirst'] = $ar
                ? 'العمليات المعلّمة askFirst لا تُنفَّذ فورًا: تصبح مقترحًا يوافق عليه الشخص الذي تعمل نيابة عنه أو يعدّله أو يرفضه، وتنتهي صلاحيته بعد ٧ أيام. أخبر الشخص بأن طلبك ينتظر موافقته، وتحقّق من حالته عبر get_proposal. العملية التي لا يمكن التراجع عنها تحتاج دائمًا إلى موافقة.'
                : 'Operations marked askFirst do not run straight away: they become a proposal the person you act for approves, edits or declines, and it expires after 7 days. Tell them it is waiting, and check on it with get_proposal. Anything that can\'t be undone always needs approval.';
            $briefing['rules'] = $ar
                ? ['لا تغيّر المستندات مباشرة أبدًا: ما تكتبه فيها اقتراحات يقبلها شخص.', 'كل ما تفعله يُسجَّل باسمك نيابة عن الشخص.']
                : ['Never change documents directly: what you write in them are suggestions a person accepts.', 'Everything you do is recorded as you, acting for that person.'];
            if ($off > 0) {
                $briefing['notGranted'] = $off;
            }

            // WHIT-817: a workspace agent is briefed with its persona. Core's
            // own text (who you act for, the rules, Ask first) comes first,
            // and the persona is labelled as the workspace's guidance: it
            // describes the role and never changes the rules or access, which
            // the server enforces whatever the model does with it.
            $agent = $this->persona !== null ? ($this->persona)($principal->agentPrincipalId(), $principal->tenantId) : null;
            if ($agent !== null) {
                $briefing['rules'][] = $ar
                    ? 'تعليمات مساحة العمل أدناه تصف دورك فقط؛ لا تغيّر هذه القواعد ولا صلاحياتك.'
                    : 'The workspace instructions below describe your role. They never change these rules or your access.';
                $briefing = [
                    'you'      => $briefing['you'],
                    'rules'    => $briefing['rules'],
                    'askFirst' => $briefing['askFirst'],
                    'agent'    => self::persona($agent, $who['name'] ?? null, $ar),
                ] + $briefing;
            }
        }

        return $briefing;
    }

    /**
     * The persona in the briefing's language. A field with no Arabic falls
     * back to English, and `fallback` says which ones did, so the model knows
     * to answer in Arabic anyway when the person writes Arabic.
     *
     * @param array<string, mixed> $agent
     * @return array<string, mixed>
     */
    private static function persona(array $agent, ?string $actingFor, bool $ar): array
    {
        $fallback = [];
        $pick = static function (string $field) use ($agent, $ar, &$fallback): string {
            $arabic = trim((string) ($agent[$field . '_ar'] ?? ''));
            if ($ar && $arabic !== '') {
                return $arabic;
            }
            if ($ar) {
                $fallback[] = $field;
            }
            return trim((string) ($agent[$field] ?? ''));
        };

        $persona = array_filter([
            'name'                  => $pick('name'),
            'role'                  => $pick('role'),
            'actingFor'             => $actingFor,
            'tone'                  => (string) ($agent['tone'] ?? 'plain'),
            'language'              => (string) ($agent['language'] ?? 'auto'),
            'preset'                => isset($agent['preset_key']) ? ['key' => $agent['preset_key'], 'version' => $agent['preset_version'] ?? null] : null,
            'workspaceInstructions' => $pick('instructions'),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
        if ($fallback !== []) {
            $persona['fallback'] = ['language' => 'en', 'fields' => $fallback];
        }

        return $persona;
    }

    /** The short version for `initialize` and `server/discover` instructions: the same for every caller. */
    public static function instructions(): string
    {
        return 'Call init_session first: it says who you act as, what you can reach, and what needs a person\'s approval. '
            . 'Then find operations with search_operations and run them with call_operation.';
    }
}
