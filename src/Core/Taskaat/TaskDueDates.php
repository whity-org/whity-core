<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\Calendar\Interval;

/**
 * Tasks due on the calendar (ADR 0018, WHIT-1114).
 *
 * Taskaat is the engine for tasks (T.3), so a due date is read from it when the
 * calendar asks and never stored in Whity. A task due shows as an all-day item
 * of kind `task_due` on its date. It is never busy and opens the task.
 * Calendars ask only when Tasks due is on in Also show (off by default, owner).
 *
 * Fails open like an outside busy source. When Taskaat isn't connected or
 * doesn't answer, the calendar shows everything else and says tasks couldn't
 * be loaded (`null`), never an error.
 */
final class TaskDueDates
{
    public const KIND = 'task_due';

    /** Most tasks one calendar read asks Taskaat for. */
    private const LIMIT = 200;

    public function __construct(
        private readonly TaskaatClient $client,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Open tasks due within `[from, to)`, as calendar items, for these people
     * (empty = everyone's). Null when Taskaat couldn't be asked.
     *
     * @param list<int> $profileIds
     * @return list<array<string, mixed>>|null
     */
    public function between(int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $zone, array $profileIds = []): ?array
    {
        if (!$this->client->connection()->isConnected($tenantId)) {
            return [];
        }
        $calls = $profileIds === []
            ? [['status' => 'open', 'limit' => self::LIMIT]]
            : array_map(static fn (int $id): array => ['status' => 'open', 'limit' => self::LIMIT, 'assignee_ref' => TaskPeople::personRef($tenantId, $id)], $profileIds);

        $tasks = [];
        try {
            foreach ($calls as $arguments) {
                $result = $this->client->callJson($tenantId, 'list_task_records', $arguments);
                foreach (is_array($result['tasks'] ?? null) ? $result['tasks'] : [] as $task) {
                    if (is_array($task) && is_string($task['id'] ?? null)) {
                        $tasks[$task['id']] = $task;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger?->warning('[calendar] tasks due could not be read from Taskaat', ['error' => $e->getMessage()]);

            return null;
        }

        $firstDay = $from->setTimezone($zone)->format('Y-m-d');
        $lastDay = $to->setTimezone($zone)->modify('-1 second')->format('Y-m-d');
        $items = [];
        foreach ($tasks as $task) {
            $due = is_string($task['due_date'] ?? null) ? substr($task['due_date'], 0, 10) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || $due < $firstDay || $due > $lastDay) {
                continue;
            }
            $start = new DateTimeImmutable($due . ' 00:00:00', $zone);
            $items[] = [
                'id' => self::KIND . ':' . $task['id'],
                'kind' => self::KIND,
                'title' => is_string($task['title'] ?? null) ? $task['title'] : '',
                'starts_at' => Interval::toApi($start),
                'ends_at' => Interval::toApi($start->modify('+1 day')),
                'all_day' => true,
                'busy' => false,
                'status' => is_string($task['status'] ?? null) ? $task['status'] : 'open',
                'organiser_id' => self::assignee($tenantId, $task),
                'people' => [],
                'room_id' => null,
                'location' => '',
                'booking' => null,
                'event' => null,
                'task' => [
                    'id' => $task['id'],
                    'short_id' => is_string($task['short_id'] ?? null) ? $task['short_id'] : null,
                ],
                'can' => ['open' => true, 'move' => false, 'resize' => false, 'cancel' => false],
            ];
        }

        return $items;
    }

    /**
     * The Whity person a task is assigned to, or 0 for an agent or nobody.
     *
     * @param array<string, mixed> $task
     */
    private static function assignee(int $tenantId, array $task): int
    {
        $ref = is_array($task['assignee'] ?? null) && is_string($task['assignee']['ref'] ?? null) ? $task['assignee']['ref'] : '';
        $prefix = 'whity:' . $tenantId . ':person:';

        return str_starts_with($ref, $prefix) ? (int) substr($ref, strlen($prefix)) : 0;
    }
}
