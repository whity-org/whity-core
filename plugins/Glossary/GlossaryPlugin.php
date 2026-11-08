<?php

declare(strict_types=1);

namespace Glossary;

use Glossary\Api\GlossaryTermsApiHandler;
use Glossary\Migrations\CreateGlossaryTermsTable;
use Glossary\Migrations\GrantGlossaryPermissionsToAdmin;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\I18n\LocalizedText;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

/**
 * The reference plugin for translatable content fields (#521).
 *
 * A workspace keeps a list of terms, each with a term and a definition in
 * Arabic and English. There are three steps to copy:
 *
 *  1. Declare the property with {@see LocalizedText::schema()} in the route's
 *     `schema`. See {@see self::components()}.
 *  2. Store the object as JSON text. See {@see CreateGlossaryTermsTable}.
 *  3. Read it with {@see LocalizedText::normalize()} before storing it. See
 *     {@see GlossaryTermsApiHandler}.
 *
 * The `screen: 'crud'` descriptor is all the interface it needs. The host
 * shows the reader's language in the list and both languages in the form.
 * `resource.titleField` names the plain `code` column, because a title must be
 * a string and a translatable field is an object.
 *
 * Walkthrough: docs/wiki/Plugin-Localized-Text.md.
 */
final class GlossaryPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    public function getName(): string
    {
        return 'Glossary';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSdkConstraint(): string
    {
        return '^1.93';
    }

    public function getCoreConstraint(): string
    {
        return '';
    }

    public function getPluginDependencies(): array
    {
        return [];
    }

    public function getRoutes(): array
    {
        $unknownOrForbidden = [
            403 => ['description' => 'Missing permission or unresolved workspace'],
            404 => ['description' => 'Term not found in this workspace'],
        ];

        return [
            [
                'method' => 'GET',
                'path' => '/api/glossary/terms',
                'handler' => [$this, 'listTerms'],
                'requiredRole' => null,
                'requiredPermission' => 'glossary:view',
                'schema' => [
                    'summary' => 'List the workspace\'s glossary terms',
                    'tags' => ['glossary'],
                    'responses' => [
                        200 => 'GlossaryTermListResponse',
                        403 => $unknownOrForbidden[403],
                    ],
                    'components' => self::components(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/glossary/terms',
                'handler' => [$this, 'createTerm'],
                'requiredRole' => null,
                'requiredPermission' => 'glossary:manage',
                'schema' => [
                    'summary' => 'Add a glossary term',
                    'tags' => ['glossary'],
                    'request' => 'GlossaryTermRequest',
                    'responses' => [
                        201 => 'GlossaryTermResponse',
                        400 => ['description' => 'A field is missing or not the right shape'],
                        403 => $unknownOrForbidden[403],
                    ],
                    'components' => self::components(),
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/glossary/terms/{id:\d+}',
                'handler' => [$this, 'updateTerm'],
                'requiredRole' => null,
                'requiredPermission' => 'glossary:manage',
                'schema' => [
                    'summary' => 'Change a glossary term',
                    'tags' => ['glossary'],
                    'request' => 'GlossaryTermRequest',
                    'responses' => [
                        200 => 'GlossaryTermResponse',
                        400 => ['description' => 'A field is missing or not the right shape'],
                    ] + $unknownOrForbidden,
                    'components' => self::components(),
                ],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/glossary/terms/{id:\d+}',
                'handler' => [$this, 'deleteTerm'],
                'requiredRole' => null,
                'requiredPermission' => 'glossary:manage',
                'schema' => [
                    'summary' => 'Remove a glossary term',
                    'tags' => ['glossary'],
                    'responses' => [
                        200 => 'GlossaryTermDeletedResponse',
                    ] + $unknownOrForbidden,
                    'components' => self::components(),
                ],
            ],
        ];
    }

    /**
     * The OpenAPI components the routes publish. `term` and `definition` are
     * the translatable fields.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function components(): array
    {
        return [
            'GlossaryTerm' => [
                'type' => 'object',
                'required' => ['id', 'code', 'term', 'definition'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'term' => LocalizedText::schema(),
                    'definition' => LocalizedText::schema(),
                ],
            ],
            'GlossaryTermListResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GlossaryTerm']],
                ],
            ],
            'GlossaryTermResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/GlossaryTerm'],
                ],
            ],
            'GlossaryTermDeletedResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
            'GlossaryTermRequest' => [
                'type' => 'object',
                'required' => ['code', 'term'],
                'properties' => [
                    'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'term' => LocalizedText::schema(),
                    'definition' => LocalizedText::schema(),
                ],
            ],
        ];
    }

    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'glossary-terms',
                'label' => 'Glossary',
                'icon' => 'book',
                'group' => 'plugins',
                'order' => 30,
                'screen' => 'crud',
                'resource' => [
                    'basePath' => '/api/glossary/terms',
                    'titleField' => 'code',
                ],
                'requiredPermission' => 'glossary:view',
            ],
        ];
    }

    public function getPermissions(): array
    {
        return ['glossary:view', 'glossary:manage'];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [CreateGlossaryTermsTable::class, GrantGlossaryPermissionsToAdmin::class];
    }

    /**
     * @param array<string, string> $params
     */
    public function listTerms(Request $request, array $params = []): Response
    {
        return $this->terms()->list();
    }

    /**
     * @param array<string, string> $params
     */
    public function createTerm(Request $request, array $params = []): Response
    {
        return $this->terms()->create($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateTerm(Request $request, array $params = []): Response
    {
        return $this->terms()->update($request, (int) ($params['id'] ?? 0));
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteTerm(Request $request, array $params = []): Response
    {
        return $this->terms()->delete((int) ($params['id'] ?? 0));
    }

    private function terms(): GlossaryTermsApiHandler
    {
        $database = \Whity\app(\Whity\Database\Database::class);
        if (!$database instanceof \Whity\Database\Database) {
            throw new \RuntimeException('The host did not register the shared Database service');
        }

        return new GlossaryTermsApiHandler($database->getPdo());
    }
}
