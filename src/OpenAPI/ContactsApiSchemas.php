<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for contacts and their imports (WHIT-953,
 * design 3.3 A8). Kept apart from {@see CoreApiSchemas} so the lane adds two
 * lines there and nothing else; {@see CoreApiSchemas::routes()} and
 * {@see CoreApiSchemas::components()} merge these in.
 *
 * MCP: every route is a tool in the `core.contacts` toolset. Adding a contact
 * declares an undo (archive it), so an agent can add one inside its person's
 * undo window; every other write is a proposal.
 */
final class ContactsApiSchemas
{
    private const UNDO_WINDOW_SECONDS = 1800;

    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $tags = ['contacts'];
        $one = self::data(SchemaBuilder::ref('Contact'));
        $import = self::data(SchemaBuilder::ref('ContactImport'));
        $notFound = [404 => self::error('Not found')];

        return [
            self::route('GET', '/api/contacts', 'contacts:read', [
                'summary' => 'List customers and suppliers',
                'description' => 'Sorted by name. Filter by kind, tag or a search over name, contact person, email, mobile and tax number.',
                'tags' => $tags,
                'parameters' => [
                    self::param('kind', 'string', '`customer` or `supplier`. Leave out for everyone; a contact can be both.'),
                    self::param('tag_id', 'integer', 'Only contacts with this tag.'),
                    self::param('q', 'string', 'Search text.'),
                    self::param('archived', 'string', '`true` for archived contacts instead.'),
                    self::param('page', 'integer', 'Page, from 1.'),
                    self::param('per_page', 'integer', 'Rows per page, at most 200. Default 50.'),
                ],
                'responses' => [200 => self::json('Contacts', self::object([
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('Contact')],
                    'pagination' => SchemaBuilder::ref('Pagination'),
                ], ['data', 'pagination']))] + self::auth(),
            ]),
            self::route('POST', '/api/contacts', 'contacts:write', [
                'mcp' => ['undo' => ['windowSeconds' => self::UNDO_WINDOW_SECONDS, 'operation' => 'post_api_contacts_id_archive', 'arguments' => ['id' => '/result/data/id']]],
                'summary' => 'Add a customer or supplier',
                'description' => 'Needs a name. A customer unless said otherwise; it can be both. Tags are names, made on first use. '
                    . 'When the email is the verified email of someone in the workspace, the contact is linked to them.',
                'tags' => $tags,
                'request' => 'ContactRequest',
                'responses' => [201 => self::json('The contact', $one), 422 => self::error('Missing name or a value that doesn\'t look right')] + self::auth(),
            ]),
            self::route('GET', '/api/contacts/tags', 'contacts:read', [
                'summary' => 'List the tags used on contacts, with how many contacts have each',
                'tags' => $tags,
                'responses' => [200 => self::json('Tags', self::object([
                    'data' => ['type' => 'array', 'items' => self::object(['id' => self::int(), 'name' => self::str(), 'count' => self::int()], ['id', 'name', 'count'])],
                ], ['data']))] + self::auth(),
            ]),
            self::route('GET', '/api/contacts/{id:\d+}', 'contacts:read', [
                'summary' => 'Get one customer or supplier',
                'tags' => $tags,
                'responses' => [200 => self::json('The contact', $one)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/contacts/{id:\d+}', 'contacts:write', [
                'summary' => 'Change a customer or supplier; fields left out keep their value',
                'description' => '`tags`, when sent, replaces the contact\'s tags.',
                'tags' => $tags,
                'request' => 'ContactRequest',
                'responses' => [200 => self::json('The contact', $one), 422 => self::error('A value that doesn\'t look right')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/contacts/{id:\d+}/archive', 'contacts:write', [
                'mcp' => ['undo' => ['windowSeconds' => self::UNDO_WINDOW_SECONDS, 'operation' => 'post_api_contacts_id_restore', 'arguments' => ['id' => '/arguments/id']]],
                'summary' => 'Archive a customer or supplier',
                'description' => 'Archived contacts leave the lists and can be restored.',
                'tags' => $tags,
                'responses' => [200 => self::json('The contact', $one)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/contacts/{id:\d+}/restore', 'contacts:write', [
                'summary' => 'Bring an archived customer or supplier back',
                'tags' => $tags,
                'responses' => [200 => self::json('The contact', $one)] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/contacts/imports', 'contacts:import', [
                'summary' => 'List recent contact imports',
                'tags' => $tags,
                'responses' => [200 => self::json('Imports', self::object([
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('ContactImportListItem')],
                ], ['data']))] + self::auth(),
            ]),
            self::route('POST', '/api/contacts/imports', 'contacts:import', [
                'mcp' => ['agentPolicy' => 'allow'],
                'summary' => 'Upload a spreadsheet of contacts (Excel or CSV)',
                'description' => 'Send `file` as multipart, or JSON with `file_name` and either `text` (a CSV\'s text) or `content_base64`. '
                    . 'Whity finds the header row and suggests where each column goes. Nothing changes yet.',
                'tags' => $tags,
                'request' => 'ContactImportUploadRequest',
                'responses' => [201 => self::json('The import', $import), 422 => self::error('A file Whity can\'t read')] + self::auth(),
            ]),
            self::route('GET', '/api/contacts/imports/{id:\d+}', 'contacts:import', [
                'summary' => 'Get an import: its columns, what it will do or did, and whether it can be undone',
                'tags' => $tags,
                'responses' => [200 => self::json('The import', $import)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/contacts/imports/{id:\d+}/check', 'contacts:import', [
                // Writes only the import's own draft: nothing a person would need to approve.
                'mcp' => ['agentPolicy' => 'allow'],
                'summary' => 'Check an import: what will be created, updated and skipped. Nothing changes',
                'description' => 'Rows are matched to existing contacts by `match_by` (default: tax number, then mobile, then email, whichever has a column). '
                    . 'An update fills in what the row has and never empties a field.',
                'tags' => $tags,
                'request' => 'ContactImportCheckRequest',
                'responses' => [200 => self::json('The import', $import), 409 => self::error('Already ran'), 422 => self::error('A column match Whity can\'t use')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/contacts/imports/{id:\d+}/run', 'contacts:import', [
                'summary' => 'Run a checked import',
                'description' => 'Works the rows out again against the contacts as they are now, and applies them in one go. Can be undone for 24 hours.',
                'tags' => $tags,
                'responses' => [200 => self::json('The import', $import), 409 => self::error('Not checked, or already ran')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/contacts/imports/{id:\d+}/undo', 'contacts:import', [
                'summary' => 'Undo an import, within 24 hours of it running',
                'description' => 'Contacts it added are removed, contacts it changed get their earlier values back, and the tags it added come off.',
                'tags' => $tags,
                'responses' => [200 => self::json('The import', $import), 409 => self::error('Not run, already undone, or more than 24 hours ago')] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/contacts/imports/{id:\d+}/skipped.csv', 'contacts:import', [
                'mcp' => ['expose' => false],
                'summary' => 'Download the rows an import skips, each with its reason',
                'tags' => $tags,
                'responses' => [
                    200 => ['description' => 'CSV', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]],
                    409 => self::error('Not checked yet'),
                ] + $notFound + self::auth(),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $tag = self::object(['id' => self::int(), 'name' => self::str()], ['id', 'name']);
        $counts = self::object(['create' => self::int(), 'update' => self::int(), 'skip' => self::int()], ['create', 'update', 'skip']);

        return [
            'Contact' => self::object([
                'id' => self::int(),
                'name' => self::str(),
                'contact_person' => self::str(),
                'email' => self::str(),
                'mobile' => self::str(),
                'phone' => self::str(),
                'tax_number' => self::str(),
                'payment_terms' => self::str() + ['description' => 'How they pay, in the workspace\'s words.'],
                'address' => self::str(),
                'notes' => self::str(),
                'is_customer' => self::bool(),
                'is_supplier' => self::bool(),
                'profile_id' => self::int(true) + ['description' => 'The person in Whity this contact is, when known.'],
                'profile' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => self::int(), 'display_name' => self::str()], 'description' => 'Only on a single contact.'],
                'tags' => ['type' => 'array', 'items' => $tag],
                'archived' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'name', 'contact_person', 'email', 'mobile', 'phone', 'tax_number', 'payment_terms', 'address', 'notes', 'is_customer', 'is_supplier', 'profile_id', 'tags', 'archived', 'created_at', 'updated_at']),
            'ContactRequest' => self::object([
                'name' => self::str() + ['maxLength' => 200, 'description' => 'The name of the customer or supplier, such as a company.'],
                'contact_person' => self::str() + ['maxLength' => 200, 'description' => 'Who to talk to there.'],
                'email' => self::str() + ['maxLength' => 320, 'description' => 'Email address.'],
                'mobile' => self::str() + ['maxLength' => 40, 'description' => 'Tidied: spaces and dashes go, `00` becomes `+`.'],
                'phone' => self::str() + ['maxLength' => 40, 'description' => 'Landline or office phone, tidied like mobile.'],
                'tax_number' => self::str() + ['maxLength' => 64, 'description' => 'Tax number, as on their invoices.'],
                'payment_terms' => self::str() + ['maxLength' => 200, 'description' => 'How they pay, in your own words, such as "CliQ, within 14 days".'],
                'address' => self::str() + ['maxLength' => 1000, 'description' => 'Postal or delivery address.'],
                'notes' => self::str() + ['maxLength' => 4000, 'description' => 'Anything else worth knowing.'],
                'is_customer' => self::bool() + ['description' => 'They buy from the workspace. Default true on a new contact.'],
                'is_supplier' => self::bool() + ['description' => 'The workspace buys from them. A contact can be both.'],
                'profile_id' => self::int(true) + ['description' => 'Link to a person in the workspace; null unlinks.'],
                'tags' => ['type' => 'array', 'maxItems' => 20, 'items' => self::str() + ['maxLength' => 128], 'description' => 'Tag names; the list replaces the contact\'s tags.'],
            ], []),
            'ContactImportUploadRequest' => self::object([
                'file_name' => self::str() + ['description' => 'The file\'s name, such as `customers.xlsx`.'],
                'text' => self::str() + ['description' => 'A CSV file\'s text.'],
                'content_base64' => self::str() + ['description' => 'An Excel (.xlsx) or CSV file, base64.'],
            ], []),
            'ContactImportCheckRequest' => self::object([
                'mapping' => ['type' => 'object', 'additionalProperties' => self::str(true), 'description' => 'Column index (from 0) => target key from `targets`. Columns left out are skipped.'],
                'match_by' => self::str(true) + ['enum' => ['tax_number', 'mobile', 'email', 'name', null], 'description' => 'How a row finds an existing contact.'],
                'country_code' => self::str(true) + ['description' => 'Added to local mobile and phone numbers, such as `+962`.'],
                'default_kind' => self::str() + ['enum' => ['customer', 'supplier', 'both'], 'description' => 'For rows with no customer-or-supplier column. Default customer.'],
                'add_tags' => ['type' => 'array', 'maxItems' => 10, 'items' => self::str(), 'description' => 'Tags added to every imported contact.'],
            ], ['mapping']),
            'ContactImport' => self::object([
                'id' => self::int(),
                'file_name' => self::str(),
                'status' => self::str() + ['enum' => ['uploaded', 'checked', 'imported', 'undone']],
                'row_count' => self::int(),
                'columns' => ['type' => 'array', 'items' => self::object([
                    'index' => self::int(),
                    'header' => self::str(),
                    'examples' => ['type' => 'array', 'items' => self::str()],
                    'empty' => self::bool(),
                    'suggestion' => ['type' => 'object', 'nullable' => true, 'properties' => [
                        'target' => self::str(),
                        'confidence' => self::str() + ['enum' => ['sure', 'doubtful']],
                    ]],
                ], ['index', 'header', 'examples', 'empty', 'suggestion'])],
                'targets' => ['type' => 'array', 'items' => self::object(['key' => self::str(), 'label' => self::str()], ['key', 'label'])],
                'options' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                'summary' => ['type' => 'object', 'nullable' => true, 'properties' => [
                    'create' => self::int(),
                    'update' => self::int(),
                    'skip' => self::int(),
                    'rows' => ['type' => 'array', 'items' => self::object([
                        'row' => self::int(),
                        'action' => self::str() + ['enum' => ['create', 'update', 'skip']],
                        'name' => self::str(),
                        'contact_id' => self::int(),
                        'reason' => self::str(),
                        'changes' => ['type' => 'array', 'items' => self::str()],
                    ], ['row', 'action', 'name'])],
                    'fixes' => ['type' => 'array', 'items' => self::object([
                        'row' => self::int(), 'column' => self::str(), 'from' => self::str(), 'to' => self::str(),
                    ], ['row', 'column', 'from', 'to'])],
                    'tags' => ['type' => 'array', 'items' => self::str()],
                ]],
                'imported_at' => self::str(true),
                'undone_at' => self::str(true),
                'undo_until' => self::str(true),
                'can_undo' => self::bool(),
                'created_at' => self::str(),
            ], ['id', 'file_name', 'status', 'row_count', 'columns', 'targets', 'options', 'summary', 'imported_at', 'undone_at', 'undo_until', 'can_undo', 'created_at']),
            'ContactImportListItem' => self::object([
                'id' => self::int(),
                'file_name' => self::str(),
                'status' => self::str(),
                'summary' => $counts + ['nullable' => true],
                'created_by_profile_id' => self::int(true),
                'imported_at' => self::str(true),
                'undo_until' => self::str(true),
                'can_undo' => self::bool(),
                'created_at' => self::str(),
            ], ['id', 'file_name', 'status', 'summary', 'imported_at', 'undo_until', 'can_undo', 'created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function route(string $method, string $path, string $permission, array $schema): array
    {
        return ['method' => $method, 'path' => $path, 'requiredRole' => null, 'requiredPermission' => $permission, 'schema' => $schema];
    }

    /** @return array<string, mixed> */
    private static function param(string $name, string $type, string $description): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => ['type' => $type]];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function json(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @return array<string, mixed> */
    private static function error(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Error')]]];
    }

    /** @return array<int, array<string, mixed>> */
    private static function auth(): array
    {
        return [401 => self::error('Missing or invalid authentication'), 403 => self::error('Insufficient permissions')];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function data(array $item): array
    {
        return self::object(['data' => $item], ['data']);
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function int(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'integer', 'nullable' => true] : ['type' => 'integer'];
    }

    /** @return array<string, mixed> */
    private static function str(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'string', 'nullable' => true] : ['type' => 'string'];
    }

    /** @return array<string, mixed> */
    private static function bool(): array
    {
        return ['type' => 'boolean'];
    }
}
