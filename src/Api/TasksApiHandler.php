<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taskaat\TaskaatClient;
use Whity\Core\Taskaat\TaskaatException;
use Whity\Core\Taskaat\TaskPeople;
use Whity\Core\Tenant\TenantContext;

/**
 * Tasks and Boards, read and written through the workspace's Taskaat (A10, T.2-T.4). Whity keeps
 * no copy: every call goes to Taskaat's record tools, which answer in JSON.
 *
 * - GET   /api/tasks/boards              the boards (Taskaat projects)
 * - GET   /api/tasks/boards/{board}      one board: columns, sections, labels, tasks
 * - GET   /api/tasks                     tasks: view=mine|by_me|agents|all, board, status
 * - GET   /api/tasks/{task}              one task with its checks, evidence and gate
 * - POST  /api/tasks                     a new task, optionally with someone on it
 * - PATCH /api/tasks/{task}              title, due date, priority, who, or the column
 * - POST  /api/tasks/{task}/review       the gate: accept as done, or send back with a reason
 *
 * Reads need `tasks:read`, writes `tasks:write`. Agents use the `taskaat_*` MCP tools instead,
 * where their writes are proposals; these routes are not exposed as MCP tools.
 *
 * A Taskaat that does not know the record tools yet answers 501 with words saying so, and the
 * page falls back to "Open in Taskaat".
 */
final class TasksApiHandler
{
    private const ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/';
    private const COLUMNS = ['todo' => 'pending', 'doing' => 'in_progress'];
    private const PRIORITIES = ['rush', 'high', 'medium', 'low'];

    public function __construct(
        private readonly TaskaatClient $client,
        private readonly TaskPeople $people,
    ) {
    }

    /** @param array<string, string> $params */
    public function boards(Request $request, array $params = []): Response
    {
        return $this->run($request, fn (int $tenantId): Response => Response::json([
            'data' => $this->client->callJson($tenantId, 'list_boards', []),
        ]));
    }

    /** @param array<string, string> $params */
    public function board(Request $request, array $params = []): Response
    {
        $board = (string) ($params['board'] ?? '');
        if (preg_match(self::ID, $board) !== 1) {
            return Response::error('No such board.', 404);
        }

        return $this->run($request, fn (int $tenantId): Response => Response::json([
            'data' => $this->client->callJson($tenantId, 'get_board', ['project_id' => $board]),
        ]));
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        return $this->run($request, function (int $tenantId, int $actor) use ($request): Response {
            $query = self::query($request);
            $view = is_string($query['view'] ?? null) ? $query['view'] : 'mine';
            $arguments = ['limit' => 200];

            $board = is_string($query['board'] ?? null) ? $query['board'] : '';
            if ($board !== '') {
                if (preg_match(self::ID, $board) !== 1) {
                    return Response::error('No such board.', 404);
                }
                $arguments['project_id'] = $board;
            }
            $status = is_string($query['status'] ?? null) ? $query['status'] : 'open';
            if (!in_array($status, ['open', 'pending', 'in_progress', 'done', 'all'], true)) {
                return Response::error('status is open, pending, in_progress, done or all.', 422);
            }
            $arguments['status'] = $status;

            match ($view) {
                'mine' => $arguments['assignee_ref'] = TaskPeople::personRef($tenantId, $actor),
                'by_me' => $arguments['assigned_by_ref'] = TaskPeople::personRef($tenantId, $actor),
                default => null,
            };
            if (!in_array($view, ['mine', 'by_me', 'agents', 'all'], true)) {
                return Response::error('view is mine, by_me, agents or all.', 422);
            }

            $result = $this->client->callJson($tenantId, 'list_task_records', $arguments);
            if ($view === 'agents') {
                $tasks = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];
                $result['tasks'] = array_values(array_filter(
                    $tasks,
                    static fn (mixed $task): bool => is_array($task) && is_array($task['assignee'] ?? null)
                        && ($task['assignee']['kind'] ?? null) === 'agent',
                ));
            }

            return Response::json(['data' => $result]);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $task = (string) ($params['task'] ?? '');
        if (preg_match(self::ID, $task) !== 1) {
            return Response::error('No such task.', 404);
        }

        return $this->run($request, fn (int $tenantId): Response => Response::json([
            'data' => $this->client->callJson($tenantId, 'get_task_record', ['task_id' => $task]),
        ]));
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params = []): Response
    {
        return $this->run($request, function (int $tenantId, int $actor) use ($request): Response {
            $body = self::body($request);
            $board = is_string($body['board'] ?? null) ? $body['board'] : '';
            $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
            if (preg_match(self::ID, $board) !== 1) {
                return Response::error('Choose a board.', 422);
            }
            if ($title === '' || mb_strlen($title) > 500) {
                return Response::error('Give the task a title of up to 500 characters.', 422);
            }

            $arguments = ['project_id' => $board, 'text' => $title];
            if (is_string($body['detail'] ?? null) && trim($body['detail']) !== '') {
                $arguments['detail'] = mb_substr(trim($body['detail']), 0, 20000);
            }
            $refusal = self::fields($body, $arguments);
            if ($refusal !== null) {
                return $refusal;
            }
            if (array_key_exists('assignee', $body)) {
                $assignee = $this->assignee($tenantId, $actor, $body['assignee']);
                if ($assignee instanceof Response) {
                    return $assignee;
                }
                if ($assignee !== null) {
                    $arguments['assignee'] = $assignee;
                }
            }

            $this->write($tenantId, 'create_task', $arguments);

            return Response::json(['data' => ['created' => true]], 201);
        });
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $task = (string) ($params['task'] ?? '');
        if (preg_match(self::ID, $task) !== 1) {
            return Response::error('No such task.', 404);
        }

        return $this->run($request, function (int $tenantId, int $actor) use ($request, $task): Response {
            $body = self::body($request);
            $arguments = ['task_id' => $task];
            if (array_key_exists('title', $body)) {
                $title = is_string($body['title']) ? trim($body['title']) : '';
                if ($title === '' || mb_strlen($title) > 500) {
                    return Response::error('Give the task a title of up to 500 characters.', 422);
                }
                $arguments['text'] = $title;
            }
            $refusal = self::fields($body, $arguments);
            if ($refusal !== null) {
                return $refusal;
            }
            if (array_key_exists('assignee', $body)) {
                $assignee = $this->assignee($tenantId, $actor, $body['assignee']);
                if ($assignee instanceof Response) {
                    return $assignee;
                }
                $arguments['assignee'] = $assignee;
            }

            $column = $body['column'] ?? null;
            if ($column !== null && !is_string($column)) {
                return Response::error('column is todo, doing or done.', 422);
            }
            if ($column === 'review') {
                return Response::error('A task reaches Awaiting review when its evidence is submitted.', 422);
            }
            if ($column !== null && $column !== 'done' && !isset(self::COLUMNS[$column])) {
                return Response::error('column is todo, doing or done.', 422);
            }
            if ($column !== null && $column !== 'done') {
                $arguments['status'] = self::COLUMNS[$column];
            }

            if (count($arguments) > 1) {
                $this->write($tenantId, 'update_task', $arguments);
            }
            if ($column === 'done') {
                // Taskaat runs its gates: a task with a check and no passing evidence is refused
                // here, or closes as "Done, unverified".
                $this->write($tenantId, 'complete_task', ['task_id' => $task]);
            }

            return Response::json(['data' => $this->client->callJson($tenantId, 'get_task_record', ['task_id' => $task])]);
        });
    }

    /** @param array<string, string> $params */
    public function review(Request $request, array $params = []): Response
    {
        $task = (string) ($params['task'] ?? '');
        if (preg_match(self::ID, $task) !== 1) {
            return Response::error('No such task.', 404);
        }

        return $this->run($request, function (int $tenantId) use ($request, $task): Response {
            $body = self::body($request);
            $decision = $body['decision'] ?? null;
            $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
            if ($decision !== 'accept' && $decision !== 'send_back') {
                return Response::error('decision is accept or send_back.', 422);
            }
            if ($decision === 'send_back' && $note === '') {
                return Response::error('Say why it goes back, so whoever works on it knows what to change.', 422);
            }

            $record = $this->client->callJson($tenantId, 'get_task_record', ['task_id' => $task]);
            $checks = is_array($record['checks'] ?? null) ? $record['checks'] : [];
            if ($checks === []) {
                return Response::error('This task has no check to review against.', 422);
            }
            $unmet = array_filter($checks, static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) !== 'met');
            $results = [];
            foreach ($checks as $check) {
                if (!is_array($check) || !is_string($check['id'] ?? null)) {
                    continue;
                }
                // Sending back fails what isn't met; when everything looked met, the person
                // still judged it not done, so every rule carries their reason.
                $fails = $decision === 'send_back' && ($unmet === [] || ($check['status'] ?? null) !== 'met');
                $result = ['rule_id' => $check['id'], 'status' => $fails ? 'fail' : 'pass'];
                if ($fails) {
                    $result['note'] = mb_substr($note, 0, 2000);
                }
                if ($fails === false && $note !== '') {
                    $result['note'] = mb_substr($note, 0, 2000);
                }
                $results[] = $result;
            }

            $this->write($tenantId, 'submit_task_review', ['task_id' => $task, 'validator' => 'human', 'results' => $results]);
            if ($decision === 'accept') {
                // A passing verdict leaves the task open in Taskaat; accepting is also closing it.
                $this->write($tenantId, 'complete_task', ['task_id' => $task]);
            }

            return Response::json(['data' => $this->client->callJson($tenantId, 'get_task_record', ['task_id' => $task])]);
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Who the task is with, as Taskaat stores it, from {kind: person|agent, id} or null.
     *
     * @return array<string, mixed>|Response|null
     */
    private function assignee(int $tenantId, int $actor, mixed $value): array|Response|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !in_array($value['kind'] ?? null, ['person', 'agent'], true) || !is_int($value['id'] ?? null)) {
            return Response::error('assignee is {kind: person or agent, id}.', 422);
        }
        $who = $value['kind'] === 'person' ? $this->people->person($tenantId, $value['id']) : $this->people->agent($tenantId, $value['id']);
        if ($who === null) {
            return Response::error($value['kind'] === 'person' ? 'That person is not in this workspace.' : 'That agent is not in this workspace.', 422);
        }
        $by = $this->people->person($tenantId, $actor);

        return [
            'kind' => $value['kind'],
            'ref' => $who['ref'],
            'name' => $who['name'],
            // An agent's task is done for the person who gave it.
            'for' => $value['kind'] === 'agent' ? $by : null,
            'by' => $by,
        ];
    }

    /**
     * Due date and priority, checked and copied into Taskaat's arguments.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $arguments
     */
    private static function fields(array $body, array &$arguments): ?Response
    {
        if (array_key_exists('due_date', $body)) {
            $due = $body['due_date'];
            if (!is_string($due) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1) {
                return Response::error('due_date is a date like 2026-11-30.', 422);
            }
            $arguments['due_date'] = $due;
        }
        if (array_key_exists('priority', $body)) {
            if (!in_array($body['priority'], self::PRIORITIES, true)) {
                return Response::error('priority is rush, high, medium or low.', 422);
            }
            $arguments['priority'] = $body['priority'];
        }

        return null;
    }

    /**
     * A write through Taskaat; Taskaat's refusal (a gate, a dependency) reaches the person as written.
     *
     * @param array<string, mixed> $arguments
     */
    private function write(int $tenantId, string $tool, array $arguments): void
    {
        $result = $this->client->callTool($tenantId, $tool, $arguments);
        if ($result['isError']) {
            $text = trim((string) preg_replace('/^(Taskaat: )?Error:\s*/', '', $result['text']));
            throw new TaskaatException($text !== '' ? mb_substr($text, 0, 500) : 'Taskaat refused the change.', 422);
        }
    }

    /**
     * The query string, from the path the router saw (and $_GET under a web server).
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
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

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode($request->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * @param callable(int, int): Response $work
     */
    private function run(Request $request, callable $work): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $user = $request->user;
        $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : 0;

        try {
            return $work($tenantId, $actor);
        } catch (TaskaatException $e) {
            return Response::error($e->clientMessage, $e->status, $e->errorCode !== null ? ['code' => $e->errorCode] : []);
        } catch (\Throwable $e) {
            error_log('[TasksApiHandler] failed: ' . $e->getMessage());

            return Response::error('Taskaat could not be reached. Try again in a minute.', 500);
        }
    }
}
