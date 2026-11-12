<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

use Whity\Core\RBAC\CorePermissions;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Sdk\Mcp\McpToolContext;

/**
 * Taskaat in the workspace MCP (A10): an agent working in Whity reaches the workspace's Taskaat
 * projects, tasks, done-when checks, evidence, the review gate and decisions through Whity's own
 * MCP, with Whity's permissions and agent rules on top of Taskaat's.
 *
 * Each tool forwards one Taskaat tool (`taskaat_<name>` calls Taskaat's `<name>`) with the
 * workspace's connection, and returns Taskaat's answer as it came. Taskaat checks the arguments
 * strictly, so the schemas here name the common ones without trying to restate Taskaat's.
 *
 * Reads need `tasks:read`. Writes need `tasks:write`, and an agent's write is a proposal a person
 * accepts (CLAUDE.md: agents never change anything directly). `get_task` is always a peek: in
 * Taskaat, opening a task starts it, and reading through Whity must not change anything.
 *
 * Registered under the key of this class, so the toolset is `taskaat` and the names start with
 * `taskaat_`. Registered again whenever the plugin tools are collected (the registry is reset
 * then), see public/index.php.
 */
final class TaskaatTools
{
    public const KEY = self::class;

    private const ID = ['type' => 'string', 'description' => 'Task short id (e.g. WHIT-12) or UUID.'];
    private const PROJECT = ['type' => 'string', 'description' => 'Project prefix, slug or UUID. A board is a Taskaat project.'];

    /**
     * Taskaat tool => [description, properties, required, writes].
     *
     * @var array<string, array{0: string, 1: array<string, array<string, mixed>>, 2: list<string>, 3: bool}>
     */
    private const TOOLS = [
        'list_projects' => ['List the workspace\'s Taskaat projects (each is a board) with progress.', [], [], false],
        'get_project' => ['A Taskaat project (board): its sections and open tasks with status and priority.', [
            'project_id' => self::PROJECT,
            'include_completed' => ['type' => 'boolean'],
        ], ['project_id'], false],
        'list_tasks' => ['List tasks in a Taskaat project. Status pending, in_progress, done or all; gate_status valid, invalid or unverified.', [
            'project_id' => self::PROJECT,
            'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'done', 'all']],
            'gate_status' => ['type' => 'string', 'enum' => ['valid', 'invalid', 'unverified']],
            'updated_since' => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
            'cursor' => ['type' => 'string'],
        ], ['project_id'], false],
        'get_task' => ['Read one Taskaat task: its body, done-when check, review and milestones. Reading never starts it.', [
            'task_id' => self::ID,
            'summary' => ['type' => 'boolean'],
        ], ['task_id'], false],
        'get_task_activity' => ['What happened on a Taskaat task, newest first.', ['task_id' => self::ID], ['task_id'], false],
        'get_task_gate_history' => ['The review gate\'s verdicts on a Taskaat task: sent back or accepted, by whom and why.', ['task_id' => self::ID], ['task_id'], false],
        'list_kb_entries' => ['List a Taskaat project\'s decisions and knowledge entries.', ['project_id' => self::PROJECT], ['project_id'], false],
        'create_task' => ['Create a task in a Taskaat project. Write the detail so someone with no context can act on it.', [
            'project_id' => self::PROJECT,
            'text' => ['type' => 'string', 'description' => 'Title.'],
            'detail' => ['type' => 'string'],
            'priority' => ['type' => 'string', 'enum' => ['rush', 'high', 'medium', 'low']],
            'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
            'section_id' => ['type' => 'string'],
            'milestones' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], ['project_id', 'text'], true],
        'update_task' => ['Change a Taskaat task: title, detail, priority, due date or status. Setting done runs the same checks as completing it.', [
            'task_id' => self::ID,
            'text' => ['type' => 'string'],
            'detail' => ['type' => 'string'],
            'priority' => ['type' => 'string', 'enum' => ['rush', 'high', 'medium', 'low']],
            'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'done']],
            'due_date' => ['type' => 'string'],
        ], ['task_id'], true],
        'complete_task' => ['Mark a Taskaat task done. Without passing evidence it stays Done, unverified.', ['task_id' => self::ID], ['task_id'], true],
        'uncomplete_task' => ['Reopen a done Taskaat task.', ['task_id' => self::ID], ['task_id'], true],
        'add_milestone' => ['Add a milestone to a Taskaat task.', [
            'task_id' => self::ID,
            'text' => ['type' => 'string'],
        ], ['task_id', 'text'], true],
        'complete_milestone' => ['Tick a milestone on a Taskaat task, by its position.', [
            'task_id' => self::ID,
            'index' => ['type' => 'number', 'description' => 'The milestone\'s position, from 0.'],
        ], ['task_id', 'index'], true],
        'set_output_contract' => ['Write a Taskaat task\'s done-when check: the rules its evidence must meet. An agent\'s task needs one before work starts.', [
            'task_id' => self::ID,
            'contract' => ['type' => 'object', 'description' => 'The rules, as Taskaat\'s set_output_contract takes them.'],
        ], ['task_id', 'contract'], true],
        'store_artifact' => ['Attach evidence to a Taskaat task for the reviewer.', [
            'task_id' => self::ID,
            'content' => ['type' => 'string'],
            'format' => ['type' => 'string', 'enum' => ['text', 'markdown', 'code', 'json']],
        ], ['task_id', 'content'], true],
        'submit_task_review' => ['The review gate: a verdict per done-when rule (pass or fail, with a note on any fail). A blocker failure sends the task back.', [
            'task_id' => self::ID,
            'validator' => ['type' => 'string', 'description' => 'self, human or ai-reviewer.'],
            'results' => ['type' => 'array', 'items' => ['type' => 'object']],
        ], ['task_id', 'results'], true],
        'create_kb_entry' => ['Record a decision in a Taskaat project, with the reasons, so later people and agents see why.', [
            'project_id' => self::PROJECT,
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string'],
        ], ['project_id', 'title', 'content'], true],
    ];

    public function __construct(private readonly TaskaatClient $client)
    {
    }

    /** @return list<string> Taskaat tool names forwarded. */
    public static function forwarded(): array
    {
        return array_keys(self::TOOLS);
    }

    /**
     * Add the Taskaat tools to the registry. Returns the reasons any were refused, for the log.
     *
     * @return list<string>
     */
    public function register(AuthoredToolRegistry $registry): array
    {
        $refused = $registry->registerToolsets([[
            'id' => '',
            'title' => 'Taskaat',
            'titleAr' => 'Taskaat',
            'description' => 'Tasks and boards in the workspace\'s Taskaat: checks, evidence, the review gate and decisions.',
        ]], self::KEY);

        foreach (self::TOOLS as $tool => [$description, $properties, $required, $writes]) {
            $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            $reason = $registry->register([
                'name' => 'taskaat_' . $tool,
                'description' => $description . ' Other Taskaat arguments are passed through.',
                'inputSchema' => $schema,
                'annotations' => $writes
                    ? ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true]
                    : ['readOnlyHint' => true, 'openWorldHint' => true],
                'requiredPermission' => $writes ? CorePermissions::TASKS_WRITE : CorePermissions::TASKS_READ,
                'agentPolicy' => $writes ? ToolSurface::AGENT_PROPOSE : ToolSurface::AGENT_ALLOW,
                'handler' => fn (array $arguments, McpToolContext $context): array|string => $this->forward($tool, $arguments, $context),
            ], self::KEY);
            if ($reason !== null) {
                $refused[] = $reason;
            }
        }

        return $refused;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|string
     */
    public function forward(string $tool, array $arguments, McpToolContext $context): array|string
    {
        if ($tool === 'get_task') {
            $arguments['peek'] = true;
        }

        try {
            $result = $this->client->callTool($context->tenantId, $tool, $arguments);
        } catch (TaskaatException $e) {
            return ['error' => $e->clientMessage];
        }

        return $result['isError'] ? ['error' => $result['text']] : $result['text'];
    }
}
