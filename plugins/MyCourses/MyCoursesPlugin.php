<?php

declare(strict_types=1);

namespace MyCourses;

use MyCourses\Migrations\CreateMyCoursesEnrolmentsTable;
use PDO;
use Whity\Sdk\Contribute\PluginCustomerScreensInterface;
use Whity\Sdk\Frontend\FrameBridge;
use Whity\Sdk\Http\CustomerCall;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginCustomerPermissionsInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

/**
 * The reference plugin for the customer area (SDK 1.174, WHIT-1426; design
 * section 41 §9 and §15, CA3): a learner's courses as a tab in their
 * customer area, a "Continue learning" card on their Home, and a full-screen
 * lesson player. It is the starting point for an LMS plugin.
 *
 * What it shows a plugin author:
 *  - routes for customers declare `'customer' => true` and a permission the
 *    plugin lists as customer-grade; the area's token opens nothing else;
 *  - every customer route reads {@see CustomerCall} and scopes its query by
 *    the customer's contact ids, never by an id the request names;
 *  - screens are declared in {@see self::getCustomerScreens()}: a blocks
 *    tab with a count, a Home card, and an embed page with the frame bridge;
 *  - staff keep their own route (`mycourses:manage`) to enrol a contact.
 *
 * Enrol a customer from the staff side with POST /api/mycourses/enrolments
 * {contact_id, course}; they see it at /@handle/x/my-courses.
 */
final class MyCoursesPlugin implements PluginInterface, PluginRequirementsInterface, PluginCustomerPermissionsInterface, PluginCustomerScreensInterface
{
    private const READ = 'mycourses_enrolment:read';
    private const LEARN = 'mycourses_enrolment:update';
    private const MANAGE = 'mycourses:manage';

    /** The host builds it with no arguments; tests hand it their own database. */
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    public function getName(): string
    {
        return 'MyCourses';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSdkConstraint(): string
    {
        return '^1.174';
    }

    public function getCoreConstraint(): string
    {
        return '';
    }

    public function getPluginDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [self::READ, self::LEARN, self::MANAGE];
    }

    /** Both reach only the learner's own enrolments: every customer route checks it. */
    public function getCustomerPermissions(): array
    {
        return [self::READ, self::LEARN];
    }

    public function getRoutes(): array
    {
        $tags = ['mycourses'];
        $notFound = [404 => ['description' => 'Not one of your enrolments']];
        // Customer routes are for the customer area, never agent tools.
        $schema = static fn (string $summary, array $responses, array $extra = []): array => $extra + [
            'summary' => $summary,
            'tags' => $tags,
            'mcp' => ['expose' => false],
            'responses' => $responses,
        ];
        $json = static fn (string $description): array => ['description' => $description, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];

        return [
            ['method' => 'GET', 'path' => '/api/mycourses/mine', 'handler' => [$this, 'mine'], 'requiredPermission' => self::READ, 'customer' => true,
                'schema' => $schema('The learner\'s own enrolments (customer area)', [200 => $json('Enrolments')] + $notFound)],
            ['method' => 'GET', 'path' => '/api/mycourses/mine/count', 'handler' => [$this, 'count'], 'requiredPermission' => self::READ, 'customer' => true,
                'schema' => $schema('How many of the learner\'s courses are unfinished', [200 => $json('{"count": n}')] + $notFound)],
            ['method' => 'GET', 'path' => '/api/mycourses/mine/{id:\d+}', 'handler' => [$this, 'one'], 'requiredPermission' => self::READ, 'customer' => true,
                'schema' => $schema('One of the learner\'s enrolments', [200 => $json('The enrolment')] + $notFound)],
            ['method' => 'POST', 'path' => '/api/mycourses/mine/{id:\d+}/progress', 'handler' => [$this, 'progress'], 'requiredPermission' => self::LEARN, 'customer' => true,
                'schema' => $schema('Mark the next lesson done', [200 => $json('The enrolment')] + $notFound, ['description' => 'Takes no body.'])],
            ['method' => 'GET', 'path' => '/api/mycourses/player', 'handler' => [$this, 'player'], 'requiredPermission' => self::READ, 'customer' => true,
                'schema' => $schema('The lesson player page, embedded in the customer area', [200 => ['description' => 'An HTML page', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]]] + $notFound)],
            // Staff only: never opened by the customer area's token.
            ['method' => 'GET', 'path' => '/api/mycourses/enrolments', 'handler' => [$this, 'all'], 'requiredPermission' => self::MANAGE,
                'schema' => $schema('Every enrolment in the workspace (staff)', [200 => $json('Enrolments')])],
            ['method' => 'POST', 'path' => '/api/mycourses/enrolments', 'handler' => [$this, 'enrol'], 'requiredPermission' => self::MANAGE,
                'schema' => $schema('Enrol a contact on a course (staff)', [201 => $json('Enrolled'), 422 => ['description' => 'No contact or course']], [
                    'request' => 'MyCoursesEnrolRequest',
                    'components' => ['MyCoursesEnrolRequest' => ['type' => 'object', 'required' => ['contact_id', 'course'], 'properties' => [
                        'contact_id' => ['type' => 'integer'], 'course' => ['type' => 'string', 'maxLength' => 200], 'lessons' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    ]]],
                ])],
        ];
    }

    public function getCustomerScreens(): array
    {
        return [
            [
                'id' => 'my-courses',
                'label' => ['en' => 'Courses', 'ar' => 'الدورات'],
                'placement' => 'tab',
                'screen' => 'blocks',
                'requiredPermission' => self::READ,
                'icon' => 'document',
                'order' => 10,
                'count' => ['apiPath' => '/api/mycourses/mine/count'],
                'blocks' => [
                    [
                        'type' => 'dataList',
                        'source' => '/api/mycourses/mine',
                        'itemField' => 'title',
                        'emptyText' => ['en' => 'You have no courses yet.', 'ar' => 'ليست لديك دورات بعد.'],
                    ],
                ],
            ],
            [
                'id' => 'continue-learning',
                'label' => ['en' => 'Continue learning', 'ar' => 'تابع التعلم'],
                'placement' => 'home',
                'screen' => 'blocks',
                'requiredPermission' => self::READ,
                'order' => 10,
                'blocks' => [
                    [
                        'type' => 'dataList',
                        'source' => '/api/mycourses/mine',
                        'itemField' => 'next',
                        'emptyText' => ['en' => 'Nothing to continue.', 'ar' => 'لا شيء لمتابعته.'],
                    ],
                ],
            ],
            [
                'id' => 'lesson-player',
                'label' => ['en' => 'Lesson', 'ar' => 'الدرس'],
                'placement' => 'tab',
                'screen' => 'embed',
                'requiredPermission' => self::READ,
                'icon' => 'play',
                'order' => 20,
                'embed' => ['path' => '/api/mycourses/player', 'fullScreen' => true],
            ],
        ];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [CreateMyCoursesEnrolmentsTable::class];
    }

    // ---- Customer routes: scoped by CustomerCall ----

    /** @param array<string, string> $params */
    public function mine(Request $request, array $params = []): Response
    {
        $call = CustomerCall::from($request);
        if ($call === null) {
            return Response::error('Not found', 404);
        }

        return Response::json(['data' => array_map([$this, 'present'], $this->ofContacts($call))]);
    }

    /** @param array<string, string> $params */
    public function count(Request $request, array $params = []): Response
    {
        $call = CustomerCall::from($request);
        if ($call === null) {
            return Response::error('Not found', 404);
        }
        $open = array_filter($this->ofContacts($call), static fn (array $r): bool => (int) $r['lessons_done'] < (int) $r['lessons']);

        return Response::json(['count' => count($open)]);
    }

    /** @param array<string, string> $params */
    public function one(Request $request, array $params = []): Response
    {
        $row = $this->own($request, (int) ($params['id'] ?? 0));

        return $row === null ? Response::error('Not found', 404) : Response::json(['data' => $this->present($row)]);
    }

    /**
     * Mark the next lesson done.
     *
     * @param array<string, string> $params
     */
    public function progress(Request $request, array $params = []): Response
    {
        $row = $this->own($request, (int) ($params['id'] ?? 0));
        if ($row === null) {
            return Response::error('Not found', 404);
        }
        $done = min((int) $row['lessons'], (int) $row['lessons_done'] + 1);
        $this->pdo()->prepare('UPDATE mycourses_enrolments SET lessons_done = :done WHERE id = :id AND tenant_id = :tenant')
            ->execute([':done' => $done, ':id' => (int) $row['id'], ':tenant' => (int) $row['tenant_id']]);

        return Response::json(['data' => $this->present(['lessons_done' => $done] + $row)]);
    }

    /**
     * The lesson player: a small page that talks to its own routes through
     * the frame bridge, inside the area's sandboxed frame.
     *
     * @param array<string, string> $params
     */
    public function player(Request $request, array $params = []): Response
    {
        if (CustomerCall::from($request) === null) {
            return Response::error('Not found', 404);
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font:17px/1.5 system-ui,sans-serif;margin:0;padding:24px;color:#1a1a1a}h1{font-size:24px;font-weight:600}'
            . 'button{font:inherit;min-height:48px;padding:0 20px;border-radius:10px;border:0;color:#fff;cursor:pointer}'
            . '.card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:12px 0}.muted{color:#666}</style></head>'
            . '<body><h1 id="title">…</h1><div id="list"></div>'
            . '<script>' . FrameBridge::script() . '</script>'
            . '<script>(async function(){'
            . 'var ctx=await whity.ready();var ar=(ctx.locale||"").indexOf("ar")===0;document.documentElement.dir=ctx.dir;'
            . 'var colour=(ctx.brand&&ctx.brand.color)||"#0b62c4";'
            . 'document.getElementById("title").textContent=ar?"دروسك":"Your lessons";'
            . 'async function draw(){var res=await whity.request("GET","/api/mycourses/mine");var list=document.getElementById("list");list.innerHTML="";'
            . '(res.ok?res.body.data:[]).forEach(function(c){var d=document.createElement("div");d.className="card";'
            . 'var t=document.createElement("div");t.textContent=c.title;t.style.fontWeight="600";'
            . 'var p=document.createElement("div");p.className="muted";p.textContent=c.next;d.appendChild(t);d.appendChild(p);'
            . 'if(c.done<c.lessons){var b=document.createElement("button");b.style.background=colour;b.textContent=ar?"أنهيت هذا الدرس":"I finished this lesson";'
            . 'b.onclick=async function(){await whity.request("POST","/api/mycourses/mine/"+c.id+"/progress",{});whity.toast(ar?"أحسنت!":"Well done!","success");draw();};d.appendChild(b);}'
            . 'list.appendChild(d);});whity.resize();}'
            . 'draw();})();</script></body></html>';

        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    // ---- Staff routes ----

    /** @param array<string, string> $params */
    public function all(Request $request, array $params = []): Response
    {
        $tenant = $request->getWorkspaceId();
        if ($tenant === null) {
            return Response::error('Open a workspace first', 404);
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM mycourses_enrolments WHERE tenant_id = :tenant ORDER BY id');
        $stmt->execute([':tenant' => $tenant]);

        return Response::json(['data' => array_map([$this, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC))]);
    }

    /** @param array<string, string> $params */
    public function enrol(Request $request, array $params = []): Response
    {
        $tenant = $request->getWorkspaceId();
        $body = json_decode($request->getBody(), true);
        $contactId = is_array($body) && is_int($body['contact_id'] ?? null) ? $body['contact_id'] : 0;
        $course = is_array($body) && is_string($body['course'] ?? null) ? trim($body['course']) : '';
        $lessons = is_array($body) && is_int($body['lessons'] ?? null) ? max(1, min(200, $body['lessons'])) : 8;
        if ($tenant === null || $contactId <= 0 || $course === '' || mb_strlen($course) > 200) {
            return Response::error('Choose a contact and name the course', 422);
        }
        $this->pdo()->prepare('INSERT INTO mycourses_enrolments (tenant_id, contact_id, course, lessons, lessons_done) VALUES (:tenant, :contact, :course, :lessons, 0)')
            ->execute([':tenant' => $tenant, ':contact' => $contactId, ':course' => $course, ':lessons' => $lessons]);

        return Response::json(['data' => ['enrolled' => true]], 201);
    }

    // ---- Helpers ----

    /**
     * The customer's own enrolments in this workspace.
     *
     * @return list<array<string, mixed>>
     */
    private function ofContacts(CustomerCall $call): array
    {
        if ($call->contactIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($call->contactIds), '?'));
        $stmt = $this->pdo()->prepare("SELECT * FROM mycourses_enrolments WHERE tenant_id = ? AND contact_id IN ($in) ORDER BY id");
        $stmt->execute([$call->workspaceId, ...$call->contactIds]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null One of the customer's own enrolments; null for anyone else's. */
    private function own(Request $request, int $id): ?array
    {
        $call = CustomerCall::from($request);
        if ($call === null || $id <= 0) {
            return null;
        }
        foreach ($this->ofContacts($call) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $lessons = (int) $row['lessons'];
        $done = (int) $row['lessons_done'];

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['course'] . ' · ' . $done . '/' . $lessons,
            'course' => (string) $row['course'],
            'lessons' => $lessons,
            'done' => $done,
            'next' => $done < $lessons ? (string) $row['course'] . ': lesson ' . ($done + 1) : (string) $row['course'] . ': finished',
        ];
    }

    private function pdo(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }
        $database = \Whity\app(\Whity\Database\Database::class);
        if (!$database instanceof \Whity\Database\Database) {
            throw new \RuntimeException('The host did not register the shared Database service');
        }

        return $database->getPdo();
    }
}
