<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The jobs a plugin declares in `plugin.json` (SDK 1.163, decision D4): one
 * declaration per job, with its handler class and, optionally, when it runs
 * and whether a workspace may ask for it.
 *
 * ```json
 * "jobs": {
 *     "catalog.sync": {"class": "Acme\\Catalog\\SyncJob", "schedule": "*\/15 * * * *", "submittable": true}
 * }
 * ```
 *
 * `class` is a {@see \Whity\Sdk\JobInterface} with a constructor that takes
 * no arguments; {@see ManifestPlugin} builds it. `schedule` is a five-field
 * cron expression (minute, hour, day of month, month, day of week), the
 * {@see \Whity\Sdk\Jobs\PluginScheduledJobsInterface} rule. `submittable`
 * lists the job in {@see \Whity\Sdk\PluginJobsInterface::getSubmittableJobs()}.
 *
 * Checked here from the document alone: names, the class name's shape and the
 * cron syntax. Whether the class exists is checked by `whity-cli plugin:validate`
 * and by the host when it loads the plugin. Pure and static.
 */
final class JobsValidator
{
    /** Bare job name: lowercase, dotted segments, no colon (the host adds the namespace). */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    private const CLASS_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    /** Each cron field's inclusive range. */
    private const CRON_FIELDS = [['minute', 0, 59], ['hour', 0, 23], ['day of month', 1, 31], ['month', 1, 12], ['day of week', 0, 7]];

    private function __construct()
    {
    }

    /** @return list<string> each naming its path; empty when valid */
    public static function errors(mixed $jobs, string $path = 'jobs'): array
    {
        if ($jobs === null) {
            return [];
        }
        if (!is_array($jobs) || ($jobs !== [] && array_is_list($jobs))) {
            return ["{$path}: must be an object of job name => declaration"];
        }

        $errors = [];
        foreach ($jobs as $name => $declaration) {
            $name = (string) $name;
            $at = "{$path}.{$name}";
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                $errors[] = "{$at}: name must be lowercase letters, digits and underscores in dotted segments, starting with a letter";
                continue;
            }
            if (!is_array($declaration)) {
                $errors[] = "{$at}: must be an object with a class";
                continue;
            }
            foreach (array_keys($declaration) as $field) {
                if (!in_array($field, ['class', 'schedule', 'submittable'], true)) {
                    $errors[] = "{$at}.{$field}: unknown field; expected class, schedule, submittable";
                }
            }
            $class = $declaration['class'] ?? null;
            if (!is_string($class) || preg_match(self::CLASS_PATTERN, $class) !== 1) {
                $errors[] = "{$at}.class: required, the handler's fully qualified class name (Vendor\\Plugin\\SyncJob)";
            }
            if (array_key_exists('schedule', $declaration)) {
                $cron = $declaration['schedule'];
                $problem = is_string($cron) ? self::cronProblem($cron) : 'must be a cron expression';
                if ($problem !== null) {
                    $errors[] = "{$at}.schedule: {$problem}";
                }
            }
            if (array_key_exists('submittable', $declaration) && !is_bool($declaration['submittable'])) {
                $errors[] = "{$at}.submittable: must be true or false";
            }
        }

        return $errors;
    }

    /** Why a cron expression is not one the host's scheduler reads, or null. */
    public static function cronProblem(string $expression): ?string
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            return 'needs exactly 5 fields (minute hour day-of-month month day-of-week)';
        }
        foreach ($fields as $i => $field) {
            [$label, $min, $max] = self::CRON_FIELDS[$i];
            foreach (explode(',', $field) as $part) {
                $part = trim($part);
                if ($part === '') {
                    return "the {$label} field has an empty part";
                }
                if (str_contains($part, '/')) {
                    [$part, $step] = explode('/', $part, 2);
                    if (!ctype_digit($step) || (int) $step < 1) {
                        return "the {$label} field has a bad step: {$step}";
                    }
                }
                if ($part === '*') {
                    continue;
                }
                $bounds = str_contains($part, '-') ? explode('-', $part, 2) : [$part, $part];
                if (!ctype_digit($bounds[0]) || !ctype_digit($bounds[1])) {
                    return "the {$label} field has a value that is not a number: {$part}";
                }
                if ((int) $bounds[0] < $min || (int) $bounds[1] > $max || (int) $bounds[0] > (int) $bounds[1]) {
                    return "the {$label} field must stay within {$min}-{$max}: {$part}";
                }
            }
        }

        return null;
    }
}
