<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for the customer area (WHIT-1416, design
 * section 41). Merged into {@see CoreApiSchemas}.
 *
 * The customer routes carry no permission: the workspace is the handle, the
 * caller must be signed in with an active membership there, and every answer
 * is bound to their own contacts. They are never MCP tools: an agent acts in
 * the team app, not as a customer. The settings pair is a staff screen and is
 * not a tool either.
 */
final class CustomerAreaApiSchemas
{
    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $base = '/api/customer-area/{handle:[a-z0-9][a-z0-9-]{0,63}}';
        $handle = ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The workspace\'s @handle.'];
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
        $common = [401 => self::error('Not signed in'), 404 => self::error('Not here, not yours, or the area is off'), 429 => self::error('Too many requests')];
        $refused = [409 => self::error('Not possible now'), 422 => self::error('A value that doesn\'t look right')];
        $route = static fn (string $method, string $path, array $schema): array => [
            'method' => $method, 'path' => $base . $path, 'requiredRole' => null, 'requiredPermission' => null,
            'schema' => $schema + ['tags' => ['customer-area'], 'mcp' => ['expose' => false]],
        ];
        $booking = self::data(SchemaBuilder::ref('CustomerAreaBooking'));
        $quote = self::data(SchemaBuilder::ref('CustomerAreaQuote'));

        return [
            $route('GET', '', [
                'summary' => 'A workspace\'s customer area: its brand, who is looking and their tabs',
                'description' => 'Answers signed out too, with `me: null`, so the front door and sign-in page can draw themselves. '
                    . '`me.state` is `customer` (an active membership, staff included), `pending` (a sign-up waiting for approval) or `none`. '
                    . 'Opening it links the workspace\'s unlinked contacts that carry one of the person\'s VERIFIED email addresses.',
                'parameters' => [$handle],
                'responses' => [200 => self::json('The area', self::data(SchemaBuilder::ref('CustomerArea')))] + [404 => self::error('No such workspace'), 429 => self::error('Too many requests')],
            ]),
            $route('POST', '/join', [
                'summary' => 'Join the workspace as a customer with the identity already proved',
                'description' => 'Under the workspace\'s sign-up rules: `joined` when open, `pending` when it needs approval, `closed` otherwise. Takes no body.',
                'parameters' => [$handle],
                'responses' => [200 => self::json('What happened', self::data(self::object(['result' => self::str() + ['enum' => ['joined', 'pending', 'closed']]], ['result'])))] + $common + $refused,
            ]),
            $route('GET', '/home', [
                'summary' => 'Home: what is waiting, the next booking, the latest message',
                'parameters' => [$handle],
                'responses' => [200 => self::json('Home', self::data(SchemaBuilder::ref('CustomerAreaHome')))] + $common,
            ]),
            $route('GET', '/bookings', [
                'summary' => 'The customer\'s bookings',
                'parameters' => [$handle, self::param('when', 'string', '`upcoming` (default) or `past`.')],
                'responses' => [200 => self::json('Bookings', self::data(self::object([
                    'time_zone' => self::str(),
                    'bookings' => ['type' => 'array', 'items' => SchemaBuilder::ref('CustomerAreaBookingRow')],
                ], ['time_zone', 'bookings'])))] + $common,
            ]),
            $route('GET', '/bookings/{id:\d+}', [
                'summary' => 'One of the customer\'s bookings',
                'parameters' => [$handle, $id],
                'responses' => [200 => self::json('The booking', $booking)] + $common,
            ]),
            $route('GET', '/bookings/{id:\d+}/slots', [
                'summary' => 'Free times to move the booking to',
                'parameters' => [$handle, $id, self::param('from', 'string', 'From this instant, with an offset or Z.'), self::param('to', 'string', 'Up to this instant, at most 14 days on.')],
                'responses' => [200 => self::json('Free times', self::object(['data' => ['type' => 'array', 'items' => self::object(['starts_at' => self::str(), 'ends_at' => self::str()], ['starts_at', 'ends_at'])]], ['data']))] + $common,
            ]),
            $route('POST', '/bookings/{id:\d+}/move', [
                'summary' => 'Move the booking to a free time',
                'parameters' => [$handle, $id],
                'request' => 'CustomerAreaMoveRequest',
                'responses' => [200 => self::json('The booking', $booking)] + $common + $refused,
            ]),
            $route('POST', '/bookings/{id:\d+}/cancel', [
                'summary' => 'Cancel the booking',
                'parameters' => [$handle, $id],
                'request' => 'CustomerAreaCancelRequest',
                'responses' => [200 => self::json('The booking', $booking)] + $common + $refused,
            ]),
            $route('GET', '/bills', [
                'summary' => 'Quotes, invoices, credit notes and receipts, newest first',
                'parameters' => [$handle, self::param('kind', 'string', '`quote`, `invoice`, `credit_note` or `receipt`; all when left out.')],
                'responses' => [200 => self::json('Bills', self::data(self::object([
                    'bills' => ['type' => 'array', 'items' => SchemaBuilder::ref('CustomerAreaBill')],
                    'how_to_pay' => SchemaBuilder::ref('CustomerAreaText'),
                ], ['bills', 'how_to_pay'])))] + $common,
            ]),
            $route('GET', '/bills/quotes/{id:\d+}', [
                'summary' => 'One of the customer\'s quotes, as sent',
                'parameters' => [$handle, $id],
                'responses' => [200 => self::json('The quote', $quote)] + $common,
            ]),
            $route('POST', '/bills/quotes/{id:\d+}/accept', [
                'summary' => 'Accept the quote, in the customer\'s own name',
                'parameters' => [$handle, $id],
                'request' => 'CustomerAreaQuoteAnswer',
                'responses' => [200 => self::json('The quote, accepted', $quote)] + $common + $refused,
            ]),
            $route('POST', '/bills/quotes/{id:\d+}/decline', [
                'summary' => 'Decline the quote',
                'parameters' => [$handle, $id],
                'request' => 'CustomerAreaQuoteAnswer',
                'responses' => [200 => self::json('The quote, declined', $quote)] + $common + $refused,
            ]),
            $route('GET', '/bills/invoices/{id:\d+}', [
                'summary' => 'One of the customer\'s invoices or credit notes, with its lines',
                'parameters' => [$handle, $id],
                'responses' => [200 => self::json('The invoice', self::data(SchemaBuilder::ref('CustomerAreaInvoice')))] + $common,
            ]),
            $route('GET', '/bills/receipts/{id:\d+}', [
                'summary' => 'One of the customer\'s receipts',
                'parameters' => [$handle, $id],
                'responses' => [200 => self::json('The receipt', self::data(SchemaBuilder::ref('CustomerAreaReceipt')))] + $common,
            ]),
            $route('GET', '/messages', [
                'summary' => 'The customer\'s conversations with the workspace',
                'parameters' => [$handle],
                'responses' => [200 => self::json('Conversations', self::data(self::object([
                    'can_start' => self::bool(),
                    'conversations' => ['type' => 'array', 'items' => self::object([
                        'id' => self::int(), 'subject' => self::str(true), 'status' => self::str(), 'last_message_at' => self::str(),
                    ], ['id', 'status', 'last_message_at'])],
                ], ['can_start', 'conversations'])))] + $common,
            ]),
            $route('POST', '/messages', [
                'summary' => 'Write to the workspace: continues the open chat or starts one',
                'parameters' => [$handle],
                'request' => 'CustomerAreaMessageRequest',
                'responses' => [201 => self::json('The message', self::data(self::object([
                    'conversation_id' => self::int(), 'message' => SchemaBuilder::ref('CustomerAreaMessage'),
                ], ['conversation_id', 'message'])))] + $common + $refused,
            ]),
            $route('GET', '/messages/{id:\d+}', [
                'summary' => 'One conversation, the customer\'s view (no notes)',
                'parameters' => [$handle, $id, self::param('after', 'integer', 'Only messages after this id.')],
                'responses' => [200 => self::json('The conversation', self::data(self::object([
                    'id' => self::int(), 'subject' => self::str(true), 'status' => self::str(),
                    'messages' => ['type' => 'array', 'items' => SchemaBuilder::ref('CustomerAreaMessage')],
                ], ['id', 'status', 'messages'])))] + $common,
            ]),
            $route('POST', '/messages/{id:\d+}', [
                'summary' => 'Reply in a conversation',
                'parameters' => [$handle, $id],
                'request' => 'CustomerAreaMessageRequest',
                'responses' => [201 => self::json('The message', self::data(SchemaBuilder::ref('CustomerAreaMessage')))] + $common + $refused,
            ]),
            $route('GET', '/x/{screen:[a-z][a-z0-9-]{0,63}}', [
                'summary' => 'One plugin screen for customers (SDK 1.174)',
                'description' => 'Only a screen whose permission the customer holds. Its blocks and embed call the plugin customer routes with the token from the route below.',
                'parameters' => [$handle, ['name' => 'screen', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => [200 => self::json('The screen', self::data(SchemaBuilder::ref('CustomerAreaScreen')))] + $common,
            ]),
            $route('POST', '/x/{screen:[a-z][a-z0-9-]{0,63}}/token', [
                'summary' => 'A five-minute token for a plugin screen (SDK 1.174)',
                'description' => 'Bound to this workspace and marked for customers: it opens only the routes of that plugin declared customer, sent as a Bearer header. Takes no body.',
                'parameters' => [$handle, ['name' => 'screen', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => [200 => self::json('The token', self::data(self::object(['token' => self::str(), 'expires_in' => self::int()], ['token', 'expires_in'])))] + $common,
            ]),
            $route('GET', '/account', [
                'summary' => 'The customer\'s account here: name, forms sent, other places',
                'parameters' => [$handle],
                'responses' => [200 => self::json('The account', self::data(SchemaBuilder::ref('CustomerAreaAccount')))] + $common,
            ]),
            $route('POST', '/leave', [
                'summary' => 'Leave the workspace as a customer; the business keeps its records',
                'description' => 'Takes no body. Staff leave from My workspaces instead (409).',
                'parameters' => [$handle],
                'responses' => [200 => self::json('Left', self::data(self::object(['result' => self::str()], ['result'])))] + $common + $refused,
            ]),
            [
                'method' => 'GET', 'path' => '/api/settings/customer-area', 'requiredRole' => null, 'requiredPermission' => 'settings:read',
                'schema' => [
                    'summary' => 'The customer area settings',
                    'tags' => ['settings'], 'mcp' => ['expose' => false],
                    'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('CustomerAreaSettings')))] + self::auth(),
                ],
            ],
            [
                'method' => 'PUT', 'path' => '/api/settings/customer-area', 'requiredRole' => null, 'requiredPermission' => 'settings:write',
                'schema' => [
                    'summary' => 'Change the customer area settings',
                    'description' => 'Any of the fields; the rest stay.',
                    'tags' => ['settings'], 'mcp' => ['expose' => false],
                    'request' => 'CustomerAreaSettingsRequest',
                    'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('CustomerAreaSettings')))] + [422 => self::error('A value that doesn\'t look right')] + self::auth(),
                ],
            ],
            [
                'method' => 'POST', 'path' => '/api/settings/customer-area/screens/{screen:[a-z][a-z0-9-]{0,63}}/grant', 'requiredRole' => null, 'requiredPermission' => 'roles:write',
                'schema' => [
                    'summary' => 'Give customers a plugin screen',
                    'description' => 'After the admin confirms (WHIT-1438, Q8): adds the screen\'s permission to the customer sign-up role and the roles of existing customers, '
                        . 'only the workspace\'s own roles that no staff hold (the others are listed as skipped), and turns the screen on. '
                        . 'Only what the caller holds can be given (403 otherwise).',
                    'tags' => ['settings'], 'mcp' => ['expose' => false],
                    'parameters' => [['name' => 'screen', 'in' => 'path', 'required' => true, 'description' => 'The plugin screen\'s id.', 'schema' => ['type' => 'string']]],
                    'request' => 'CustomerAreaScreenGrantRequest',
                    'responses' => [
                        200 => self::json('Given, with the settings', self::data(SchemaBuilder::ref('CustomerAreaSettings'))),
                        403 => self::error('The caller does not hold the permission'),
                        404 => self::error('No enabled plugin offers the screen'),
                        409 => self::error('The screen\'s permission cannot be given to customers'),
                        422 => self::error('Not confirmed'),
                    ] + self::auth(),
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $text = SchemaBuilder::ref('CustomerAreaText');
        $named = ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'A name in each language, such as {"en": "Haircut", "ar": "قص شعر"}.'];
        $bookingRow = self::object([
            'id' => self::int(), 'status' => self::str(), 'starts_at' => self::str(), 'ends_at' => self::str(),
            'service_name' => $named, 'staff_name' => self::str(true), 'room_name' => self::str(true),
            'price_minor' => self::int(true), 'currency' => self::str(true), 'checked_in' => self::bool(),
        ], ['id', 'status', 'starts_at', 'ends_at']);
        $bill = self::object([
            'kind' => self::str() + ['enum' => ['quote', 'invoice', 'credit_note', 'receipt']],
            'id' => self::int(), 'number' => self::str(), 'revision' => self::int(), 'status' => self::str(),
            'date' => self::str(true), 'valid_until' => self::str(true), 'due_date' => self::str(true), 'overdue' => self::bool(),
            'currency' => self::str(), 'total_minor' => self::int(), 'due_minor' => self::int(), 'waiting' => self::bool(),
        ], ['kind', 'id', 'number', 'status', 'currency', 'total_minor', 'waiting']);

        return [
            'CustomerAreaText' => self::object(['en' => self::str(), 'ar' => self::str()], ['en', 'ar']),
            'CustomerArea' => self::object([
                'workspace' => self::object(['name' => self::str(), 'handle' => self::str(), 'brand' => ['type' => 'object']], ['name', 'handle', 'brand']),
                'enabled' => self::bool(),
                'signup' => self::str() + ['enum' => ['off', 'open', 'approval']],
                'booking' => self::bool(),
                'chat' => self::bool(),
                'welcome' => $text,
                'me' => self::object([
                    'name' => self::str(), 'email' => self::str(),
                    'state' => self::str() + ['enum' => ['customer', 'pending', 'none']],
                    'staff' => self::bool(),
                ], ['name', 'email', 'state', 'staff']) + ['nullable' => true],
                'tabs' => ['type' => 'array', 'items' => self::object([
                    'id' => self::str() + ['description' => '`bookings`, `bills`, `messages`, or `x:<screen id>` for a plugin tab (SDK 1.174).'],
                    'count' => self::int(),
                    'label' => ['description' => 'A plugin tab label: a string or {en, ar}.'],
                    'icon' => self::str(true),
                    'count_path' => self::str(true) + ['description' => 'The count route of a plugin tab, called with the screen token; it answers {"count": n}.'],
                ], ['id', 'count'])],
            ], ['workspace', 'enabled', 'signup', 'booking', 'chat', 'welcome', 'me', 'tabs']),
            'CustomerAreaBookingRow' => $bookingRow,
            'CustomerAreaBill' => $bill,
            'CustomerAreaHome' => self::object([
                'cards' => ['type' => 'array', 'items' => self::object([
                    'id' => self::str(), 'label' => ['description' => 'A string or {en, ar}.'], 'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'api_paths' => ['type' => 'array', 'items' => self::str()],
                ], ['id', 'label', 'blocks', 'api_paths']), 'description' => 'Home cards from plugins (placement home, SDK 1.174).'],
                'waiting' => ['type' => 'array', 'items' => SchemaBuilder::ref('CustomerAreaBill')],
                'time_zone' => self::str(),
                'next_booking' => SchemaBuilder::ref('CustomerAreaBookingRow') + ['nullable' => true],
                'latest_message' => self::object([
                    'id' => self::int(), 'last_message_at' => self::str(),
                    'message' => SchemaBuilder::ref('CustomerAreaMessage') + ['nullable' => true],
                ], ['id', 'last_message_at']) + ['nullable' => true],
            ], ['cards', 'waiting', 'time_zone', 'next_booking', 'latest_message']),
            'CustomerAreaScreen' => self::object([
                'id' => self::str(),
                'label' => ['description' => 'A string or {en, ar}.'],
                'placement' => self::str() + ['enum' => ['tab', 'home', 'page']],
                'screen' => self::str() + ['enum' => ['blocks', 'embed']],
                'icon' => self::str(true),
                'api_paths' => ['type' => 'array', 'items' => self::str(), 'description' => 'The plugin customer routes this screen token opens, as path patterns.'],
                'blocks' => ['type' => 'array', 'items' => ['type' => 'object']],
                'embed' => self::object(['path' => self::str(), 'fullScreen' => self::bool()], ['path']),
                'count' => self::object(['apiPath' => self::str()], ['apiPath']),
            ], ['id', 'label', 'placement', 'screen']),
            'CustomerAreaBooking' => self::object([
                'id' => self::int(), 'status' => self::str(), 'suggested' => self::bool(), 'starts_at' => self::str(), 'ends_at' => self::str(),
                'service' => ['type' => 'object'], 'staff_name' => self::str(), 'price_minor' => self::int(true), 'currency' => self::str(true),
                'customer' => self::object(['name' => self::str(), 'email' => self::str()], []),
                'services' => ['type' => 'array', 'items' => ['type' => 'object']],
                'room_name' => self::str(true), 'can_change' => self::bool(), 'time_zone' => self::str(),
            ], ['id', 'status', 'starts_at', 'ends_at']),
            'CustomerAreaQuote' => self::object([
                'id' => self::int(), 'number' => self::str(true), 'revision' => self::int(), 'status' => self::str(), 'customer' => self::str(),
                'issue_date' => self::str(true), 'valid_until' => self::str(), 'currency' => self::str(), 'prices_include_tax' => self::bool(),
                'subtotal_minor' => self::int(), 'tax_minor' => self::int(), 'total_minor' => self::int(), 'terms' => self::str(),
                'decided_by_name' => self::str(true), 'decided_at' => self::str(true),
                'lines' => ['type' => 'array', 'items' => ['type' => 'object']],
            ], ['id', 'status', 'currency', 'total_minor', 'lines']),
            'CustomerAreaInvoice' => self::object([
                'kind' => self::str(), 'id' => self::int(), 'number' => self::str(), 'status' => self::str(),
                'date' => self::str(true), 'due_date' => self::str(true), 'overdue' => self::bool(), 'currency' => self::str(),
                'subtotal_minor' => self::int(), 'discount_minor' => self::int(), 'tax_minor' => self::int(), 'total_minor' => self::int(),
                'paid_minor' => self::int(), 'credited_minor' => self::int(), 'due_minor' => self::int(), 'prices_include_tax' => self::bool(),
                'lines' => ['type' => 'array', 'items' => self::object([
                    'description' => self::str(), 'quantity' => self::str(), 'unit_price_minor' => self::int(),
                    'discount_bp' => self::int(), 'tax_minor' => self::int(), 'total_minor' => self::int(),
                ], ['description', 'quantity', 'total_minor'])],
                'how_to_pay' => $text,
            ], ['kind', 'id', 'number', 'status', 'currency', 'total_minor', 'due_minor', 'lines', 'how_to_pay']),
            'CustomerAreaReceipt' => self::object([
                'kind' => self::str(), 'id' => self::int(), 'number' => self::str(), 'date' => self::str(true),
                'currency' => self::str(), 'total_minor' => self::int(), 'method' => self::str(), 'reference' => self::str(),
            ], ['id', 'number', 'currency', 'total_minor']),
            'CustomerAreaMessage' => self::object([
                'id' => self::int(), 'from' => self::str() + ['enum' => ['you', 'workspace']], 'author_name' => self::str(true),
                'body' => self::str(), 'created_at' => self::str(),
            ], ['id', 'from', 'body', 'created_at']),
            'CustomerAreaAccount' => self::object([
                'name' => self::str(), 'email' => self::str(), 'staff' => self::bool(),
                'forms' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(), 'reference' => self::str(true), 'form_name' => $named,
                    'submitted_at' => self::str(true), 'withdrawn' => self::bool(),
                ], ['id', 'form_name', 'withdrawn'])],
                'other_places' => ['type' => 'array', 'items' => self::object(['name' => self::str(), 'handle' => self::str()], ['name', 'handle'])],
            ], ['name', 'email', 'staff', 'forms', 'other_places']),
            'CustomerAreaMoveRequest' => self::object(['starts_at' => self::str() + ['description' => 'The new start, with an offset or Z, from the free times.']], ['starts_at']),
            'CustomerAreaCancelRequest' => self::object(['reason' => self::str() + ['maxLength' => 500, 'description' => 'Why, optional.']], []),
            'CustomerAreaQuoteAnswer' => self::object(['reason' => self::str() + ['maxLength' => 1000, 'description' => 'Why, if declining. Optional.']], []),
            'CustomerAreaMessageRequest' => self::object(['message' => self::str() + ['maxLength' => 4000]], ['message']),
            'CustomerAreaSettings' => self::object([
                'enabled' => self::bool(),
                'hidden_tabs' => ['type' => 'array', 'items' => self::str()],
                'tab_order' => ['type' => 'array', 'items' => self::str()],
                'welcome_en' => self::str(), 'welcome_ar' => self::str(),
                'how_to_pay_en' => self::str(), 'how_to_pay_ar' => self::str(),
                'handle' => self::str(true),
                'tabs' => ['type' => 'array', 'items' => self::str()],
                'hidden_screens' => ['type' => 'array', 'items' => self::str()],
                'screens' => ['type' => 'array', 'items' => self::object([
                    'id' => self::str(),
                    'label' => ['description' => 'A string, or a name in each language.'],
                    'plugin' => self::str(),
                    'placement' => self::str() + ['enum' => ['tab', 'home', 'page']],
                    'permission' => self::str(),
                    'on' => self::bool(),
                    'customers_have' => self::bool() + ['description' => 'Every customer role holds the permission.'],
                    'roles' => ['type' => 'array', 'items' => self::object([
                        'id' => self::int(), 'name' => self::str(), 'signup' => self::bool(), 'customers' => self::int(), 'has' => self::bool(),
                        'skipped' => self::str(true) + ['description' => 'Why it cannot be given here: global or staff.'],
                    ], ['id', 'name', 'signup', 'customers', 'has', 'skipped'])],
                ], ['id', 'label', 'plugin', 'placement', 'permission', 'on', 'customers_have', 'roles'])],
                'grant' => self::object([
                    'granted' => ['type' => 'array', 'items' => self::object(['id' => self::int(), 'name' => self::str()], ['id', 'name'])],
                    'skipped' => ['type' => 'array', 'items' => self::object(['id' => self::int(), 'name' => self::str(), 'reason' => self::str()], ['id', 'name', 'reason'])],
                ], ['granted', 'skipped']) + ['description' => 'Only after a grant: what changed.'],
            ], ['enabled', 'hidden_tabs', 'tab_order', 'welcome_en', 'welcome_ar', 'how_to_pay_en', 'how_to_pay_ar', 'handle', 'tabs', 'hidden_screens', 'screens']),
            'CustomerAreaSettingsRequest' => self::object([
                'enabled' => self::bool(),
                'hidden_tabs' => ['type' => 'array', 'items' => self::str() + ['enum' => ['bookings', 'bills', 'messages']]],
                'tab_order' => ['type' => 'array', 'items' => self::str() + ['enum' => ['bookings', 'bills', 'messages']]],
                'welcome_en' => self::str() + ['maxLength' => 300], 'welcome_ar' => self::str() + ['maxLength' => 300],
                'how_to_pay_en' => self::str() + ['maxLength' => 2000], 'how_to_pay_ar' => self::str() + ['maxLength' => 2000],
                'hidden_screens' => ['type' => 'array', 'maxItems' => 200, 'items' => self::str() + ['pattern' => '^[a-z][a-z0-9-]{0,63}$'], 'description' => 'Plugin screens turned off, by id.'],
            ], []),
            'CustomerAreaScreenGrantRequest' => self::object(['confirm' => self::bool() + ['description' => 'Must be true: the admin confirmed.']], ['confirm']),
        ];
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
