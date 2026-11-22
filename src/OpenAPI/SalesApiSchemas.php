<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for quotes to customers (WHIT-1321, design
 * section 34 §3). Kept apart from {@see CoreApiSchemas}, which merges these
 * in.
 *
 * MCP: every signed-in route is a tool in the `core.sales` toolset. No write
 * declares an undo, so an agent's change is always a proposal its person
 * approves (QT.11). The customer's page is public and is not a tool.
 */
final class SalesApiSchemas
{
    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $tags = ['sales'];
        $quote = self::data(SchemaBuilder::ref('SalesQuote'));
        $notFound = [404 => self::error('Not found')];
        $refused = [409 => self::error('Not possible in the quote\'s state, or a rule refused it'), 422 => self::error('A value that doesn\'t look right')];
        $token = ['name' => 'token', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The 64-character token from the customer\'s link.'];
        $public = static fn (string $method, string $path, array $schema): array => [
            'method' => $method, 'path' => $path, 'requiredRole' => null, 'requiredPermission' => null,
            'schema' => $schema + ['tags' => ['sales']],
        ];
        $limits = [429 => self::error('Too many attempts')];

        return [
            self::route('GET', '/api/sales/quotes', 'quotes:read', [
                'summary' => 'List quotes, newest first',
                'description' => 'One row per quote: its latest revision that has gone out, or the draft when it never has.',
                'tags' => $tags,
                'parameters' => [
                    self::param('status', 'string', '`draft`, `waiting_approval`, `ready`, `sent`, `accepted`, `declined`, `expired`, `converted` or `cancelled`.'),
                    self::param('contact_id', 'integer', 'Only this customer.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'How many to skip.'),
                ],
                'responses' => [200 => self::json('Quotes, without their lines', self::list('SalesQuote'))] + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes', 'quotes:write', [
                'summary' => 'Draft a quote',
                'description' => 'A customer (a contact marked as one) and lines: an item (its price, name and tax category fill in when left out) or a free line with a description. '
                    . 'Valid for the workspace\'s number of days (30 by default) unless `valid_until` says otherwise.',
                'tags' => $tags,
                'request' => 'SalesQuoteRequest',
                'responses' => [201 => self::json('The quote', $quote), 200 => self::json('The same key was sent before', $quote)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/sales/quotes/{id:\d+}', 'quotes:read', [
                'summary' => 'A quote with its lines, revisions and what it turned into',
                'tags' => $tags,
                'responses' => [200 => self::json('The quote', $quote)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/sales/quotes/{id:\d+}', 'quotes:write', [
                'summary' => 'Change a draft quote',
                'description' => 'Any field; `lines` replaces every line. Only a draft can change; a sent quote changes through a new revision.',
                'tags' => $tags,
                'request' => 'SalesQuoteUpdateRequest',
                'responses' => [200 => self::json('The quote', $quote)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/submit', 'quotes:write', [
                'summary' => 'Send a quote for approval',
                'description' => 'It travels the workspace\'s quote route when one is chosen (`status: waiting_approval`); with none it is ready at once.',
                'tags' => $tags,
                'responses' => [200 => self::json('The quote', $quote)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/send', 'quotes:write', [
                'summary' => 'Send a quote to the customer',
                'description' => 'A ready quote, or a draft when the workspace has no quote route. It takes its number (`QUO-0318`) on first send and a link for the customer, '
                    . 'answered once in `link`; only its hash is kept. The revision before becomes `replaced`. `sales.quote.sending` may refuse it (409, `vetoed`). '
                    . 'It is emailed to the customer unless `email` is false or the workspace turned the quote email off; `emailed` says whether it was. '
                    . 'Its printed copy, with the QR of the link, is issued in the language of the workspace and named in `document_id` (null when it could not be made; the quote has gone out all the same).',
                'tags' => $tags,
                'request' => 'SalesQuoteSendRequest',
                'responses' => [200 => self::json('The quote and the customer\'s link', self::object(['data' => SchemaBuilder::ref('SalesQuote'), 'link' => self::str(), 'document_id' => self::int(true), 'emailed' => self::bool()], ['data', 'link', 'document_id', 'emailed']))]
                    + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/link', 'quotes:write', [
                'summary' => 'Make a new link to a quote',
                'description' => 'The link before it stops working. A new printed copy carries the QR of the new link (`document_id`).',
                'tags' => $tags,
                'responses' => [200 => self::json('The new link', self::object(['link' => self::str(), 'document_id' => self::int(true)], ['link', 'document_id']))] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/print', 'quotes:read', [
                'summary' => 'Print a quote',
                'description' => 'Its PDF, `{lang: "en" | "ar"}`. A quote that went out prints the copy it went out as, with its QR; a draft prints a working copy without one. '
                    . 'The id of the document is in `X-Document-Id`. When this instance cannot make PDFs, the answer is JSON naming the document, with 202.',
                'tags' => $tags,
                'request' => 'SalesQuotePrintRequest',
                'responses' => [
                    200 => ['description' => 'The PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                    202 => self::json('Issued, with no PDF here', self::object(['data' => self::object([
                        'document_id' => self::int(), 'number' => self::str(true), 'title' => self::str(), 'pdf' => self::bool(),
                    ], ['document_id', 'number', 'title', 'pdf'])], ['data'])),
                ] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/decision', 'quotes:write', [
                'summary' => 'Record the customer\'s answer',
                'description' => 'What the customer said by phone or in person: accepted, with the name of who accepted, or declined, with a reason if they gave one.',
                'tags' => $tags,
                'request' => 'SalesQuoteDecisionRequest',
                'responses' => [200 => self::json('The quote', $quote)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/revise', 'quotes:write', [
                'summary' => 'Make a new revision of a quote',
                'description' => 'A draft copy with the same number and the next revision. Asked again while that draft exists, it answers the same draft.',
                'tags' => $tags,
                'responses' => [201 => self::json('The new revision', $quote)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/cancel', 'quotes:write', [
                'summary' => 'Withdraw a quote before the customer answers',
                'tags' => $tags,
                'responses' => [200 => self::json('The quote', $quote)] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/sales/quotes/{id:\d+}/targets', 'quotes:read', [
                'summary' => 'What a quote can turn into',
                'description' => 'One entry per target the workspace has (a sales order, an invoice), with whether it is possible now and why not.',
                'tags' => $tags,
                'responses' => [200 => self::json('The targets', self::list('SalesQuoteTarget'))] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/sales/quotes/{id:\d+}/convert', 'quotes:write', [
                'summary' => 'Turn an accepted quote into an order or an invoice',
                'description' => 'Once per target: asked again, it answers what was made the first time (200).',
                'tags' => $tags,
                'request' => 'SalesQuoteConvertRequest',
                'responses' => [
                    201 => self::json('The quote and what it became', self::object(['data' => SchemaBuilder::ref('SalesQuote'), 'target' => self::object([
                        'key' => self::str(), 'id' => self::int(), 'label' => self::str(), 'link' => self::str(),
                    ], ['key', 'id', 'label'])], ['data', 'target'])),
                    200 => self::json('It was turned into that before', self::object(['data' => SchemaBuilder::ref('SalesQuote')], ['data'])),
                ] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/sales/settings', 'quotes:read', [
                'summary' => 'The sales settings',
                'tags' => $tags,
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('SalesSettings')))] + self::auth(),
            ]),
            self::route('PATCH', '/api/sales/settings', 'settings:write', [
                'summary' => 'Change the sales settings',
                'tags' => $tags,
                'request' => 'SalesSettings',
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('SalesSettings')))] + [422 => self::error('A value that doesn\'t look right')] + self::auth(),
            ]),
            $public('GET', '/api/public/quotes/{token:[a-f0-9]{64}}', [
                'summary' => 'The customer\'s quote, by the link the business sent (public)',
                'description' => 'No account needed. When a newer revision has gone out, the newest one with `replaced: true`. Sent `Cache-Control: no-store`.',
                'parameters' => [$token],
                'responses' => [
                    200 => self::json('The quote', self::data(SchemaBuilder::ref('PublicSalesQuote'))),
                    404 => self::error('This link is not valid any more'),
                ] + $limits,
            ]),
            $public('POST', '/api/public/quotes/{token:[a-f0-9]{64}}/accept', [
                'summary' => 'Accept a quote (public)',
                'parameters' => [$token],
                'request' => 'PublicSalesQuoteAnswer',
                'responses' => [
                    200 => self::json('The quote, accepted', self::data(SchemaBuilder::ref('PublicSalesQuote'))),
                    404 => self::error('This link is not valid any more'),
                    409 => self::error('Already answered, expired, withdrawn or replaced'),
                    422 => self::error('The name is missing'),
                ] + $limits,
            ]),
            $public('POST', '/api/public/quotes/{token:[a-f0-9]{64}}/decline', [
                'summary' => 'Decline a quote (public)',
                'parameters' => [$token],
                'request' => 'PublicSalesQuoteAnswer',
                'responses' => [
                    200 => self::json('The quote, declined', self::data(SchemaBuilder::ref('PublicSalesQuote'))),
                    404 => self::error('This link is not valid any more'),
                    409 => self::error('Already answered, expired, withdrawn or replaced'),
                ] + $limits,
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $statuses = ['draft', 'waiting_approval', 'ready', 'sent', 'accepted', 'declined', 'expired', 'converted', 'cancelled', 'replaced'];
        $line = [
            'variant_id' => self::int(true) + ['description' => 'The item variant; leave out for a free line.'],
            'description' => self::str() + ['description' => 'What it is. Required for a free line; an item line takes the item\'s name when left out.'],
            'quantity' => ['type' => 'string', 'pattern' => '^\d+(\.\d+)?$', 'description' => 'A decimal such as "2.5", in `unit` for an item, up to 3 decimals for a free line.'],
            'unit' => self::str() + ['description' => 'A unit code for an item line; the item\'s base unit when left out.'],
            'price_minor' => self::int() + ['description' => 'The price of one, in minor units of the quote\'s currency.'],
            'discount_bp' => self::int() + ['description' => 'Discount in basis points, 0 to 10000.'],
            'tax_bp' => self::int() + ['description' => 'Tax rate in basis points, 0 to 10000 (1600 is 16%).'],
            'tax_category' => self::str() + ['description' => 'The item\'s tax category, kept for the tax engine.'],
        ];
        $head = [
            'contact_id' => self::int() + ['description' => 'The customer: a contact marked as a customer.'],
            'valid_until' => self::str() + ['format' => 'date', 'description' => 'The last day the customer can accept, such as 2026-12-21. Today plus the workspace\'s number of days when left out.'],
            'currency' => self::str() + ['description' => 'Three letters; the workspace\'s currency when left out.'],
            'prices_include_tax' => self::bool() + ['description' => 'True when the prices typed already include tax. False by default.'],
            'notes' => self::str() + ['description' => 'For the team; never shown to the customer.'],
            'terms' => self::str() + ['description' => 'Printed on the quote and shown to the customer.'],
            'lines' => ['type' => 'array', 'items' => SchemaBuilder::ref('SalesQuoteLineRequest'), 'description' => 'The lines, 1 to 300, in order. On a change it replaces every line.'],
        ];
        $publicLine = self::object([
            'description' => self::str(), 'quantity' => self::str(), 'unit' => self::str(true), 'price_minor' => self::int(),
            'discount_bp' => self::int(), 'tax_bp' => self::int(), 'line_total_minor' => self::int(),
        ], ['description', 'quantity', 'price_minor', 'line_total_minor']);

        return [
            'SalesQuoteLineRequest' => self::object($line, ['quantity']),
            'SalesQuoteRequest' => self::object($head + ['idempotency_key' => self::str() + ['maxLength' => 128, 'description' => 'Any unique text; sending the same key again makes nothing new and answers the first quote.']], ['contact_id', 'lines']),
            'SalesQuoteUpdateRequest' => self::object($head, []),
            'SalesQuoteDecisionRequest' => self::object([
                'accepted' => self::bool() + ['description' => 'True when the customer said yes, false when they declined.'],
                'name' => self::str() + ['description' => 'Who accepted. Required when accepting.'],
                'reason' => self::str() + ['description' => 'Why they declined, if they said.'],
            ], ['accepted']),
            'SalesQuoteSendRequest' => self::object(['email' => self::bool() + ['description' => 'False to only make the link, without emailing the customer. True by default.']], []),
            'SalesQuotePrintRequest' => self::object(['lang' => self::str() + ['enum' => ['en', 'ar'], 'description' => 'The language of the template: `en` (the default) or `ar`.']], []),
            'SalesQuoteConvertRequest' => self::object(['target' => self::str() + ['description' => 'A target key from GET …/targets, such as `sales_order` or `invoice`.']], ['target']),
            'SalesQuoteTarget' => self::object([
                'key' => self::str(),
                'label' => self::object(['en' => self::str(), 'ar' => self::str()], ['en', 'ar']),
                'possible' => self::bool(),
                'reason' => self::str(),
                'done' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => self::int(), 'label' => self::str()]],
            ], ['key', 'label', 'possible', 'reason']),
            'SalesQuoteLine' => self::object([
                'id' => self::int(), 'variant_id' => self::int(true), 'item_id' => self::int(true), 'sku' => self::str(true), 'unit' => self::str(true),
                'description' => self::str(), 'quantity' => self::str(), 'price_minor' => self::int(), 'discount_bp' => self::int(),
                'tax_category' => self::str(), 'tax_bp' => self::int(),
                'line_total_minor' => self::int() + ['description' => 'Quantity times price, less the discount.'],
            ], ['id', 'description', 'quantity', 'price_minor', 'line_total_minor']),
            'SalesQuote' => self::object([
                'id' => self::int(),
                'number' => self::str(true) + ['description' => 'Taken on first send (`QUO-0318`); null before.'],
                'revision' => self::int(),
                'replaces_id' => self::int(true),
                'status' => self::str() + ['enum' => $statuses],
                'contact_id' => self::int(),
                'contact_name' => self::str(),
                'contact_email' => self::str(),
                'issue_date' => self::str(true),
                'valid_until' => self::str(),
                'currency' => self::str(),
                'prices_include_tax' => self::bool(),
                'subtotal_minor' => self::int(),
                'tax_minor' => self::int(),
                'total_minor' => self::int(),
                'notes' => self::str(),
                'terms' => self::str(),
                'route_id' => self::int(true),
                'document_id' => self::int(true),
                'source_plugin' => self::str(true),
                'created_by_profile_id' => self::int(true),
                'created_by_name' => self::str(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'approved_by_name' => self::str(true),
                'decided_by_name' => self::str(),
                'decided_by_profile_name' => self::str(true),
                'decided_online' => self::bool(),
                'decline_reason' => self::str(),
                'submitted_at' => self::str(true),
                'approved_at' => self::str(true),
                'sent_at' => self::str(true),
                'decided_at' => self::str(true),
                'converted_at' => self::str(true),
                'cancelled_at' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'lines' => ['type' => 'array', 'items' => SchemaBuilder::ref('SalesQuoteLine')],
                'conversions' => ['type' => 'array', 'items' => self::object(['target' => self::str(), 'id' => self::int(), 'label' => self::str()], ['target', 'id'])],
                'revisions' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(), 'revision' => self::int(), 'status' => self::str(), 'sent_at' => self::str(true),
                ], ['id', 'revision', 'status'])],
            ], ['id', 'revision', 'status', 'contact_id', 'contact_name', 'valid_until', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'created_at', 'updated_at']),
            'SalesSettings' => self::object([
                'quote_valid_days' => self::int() + ['description' => 'How long a new quote is valid, 1 to 365 days.'],
                'quote_route_template_id' => self::int(true) + ['description' => 'The route a quote travels before it goes out; null for none.'],
                'email_quote' => self::bool() + ['description' => 'Email a quote to the customer when it is sent.'],
                'email_invoice' => self::bool() + ['description' => 'Email an invoice to the customer when it is issued.'],
                'email_receipt' => self::bool() + ['description' => 'Email a receipt when a payment arrives.'],
                'email_reminder' => self::bool() + ['description' => 'Allow payment reminders by email.'],
            ], []),
            'PublicSalesQuote' => self::object([
                'number' => self::str(true), 'revision' => self::int(), 'status' => self::str() + ['enum' => $statuses], 'customer' => self::str(),
                'issue_date' => self::str(true), 'valid_until' => self::str(), 'currency' => self::str(), 'prices_include_tax' => self::bool(),
                'subtotal_minor' => self::int(), 'tax_minor' => self::int(), 'total_minor' => self::int(), 'terms' => self::str(),
                'decided_by_name' => self::str(true), 'decided_at' => self::str(true), 'replaced' => self::bool(),
                'lines' => ['type' => 'array', 'items' => $publicLine],
                'workspace' => self::object(['name' => self::str(), 'brand' => ['type' => 'object', 'nullable' => true]], ['name']),
            ], ['number', 'status', 'customer', 'valid_until', 'currency', 'total_minor', 'lines']),
            'PublicSalesQuoteAnswer' => self::object([
                'name' => self::str() + ['description' => 'Your name. Required to accept.'],
                'reason' => self::str() + ['description' => 'Why, if declining. Optional.'],
            ], []),
        ];
    }

    /** @return array<string, mixed> */
    private static function list(string $ref): array
    {
        return self::object(['data' => ['type' => 'array', 'items' => SchemaBuilder::ref($ref)]], ['data']);
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
