# Public forms for plugins

A plugin can take submissions from a visitor's browser with no account, no session and no proxy of its own. Declare one route and write the handler. The host does everything else before your handler runs.

Needs SDK 1.67 and core 0.2.26. **You no longer need a Cloudflare Worker, or any other proxy that checks a bot token and signs requests.** If you run one for this, delete it and its shared secret after switching. Signed webhooks (`'public' => true`) are still for servers that can sign.

## Declare the route

```php
use Whity\Sdk\Http\PublicForm;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

public function getRoutes(): array
{
    return [[
        'method'  => 'POST',
        'path'    => '/api/arcline/forms/enquiry/{key}',
        'public'  => 'form',
        'handler' => function (Request $request): Response {
            // Set by the host. Never read a tenant from the request itself.
            $form = PublicForm::from($request);

            $body = json_decode($request->getBody(), true);
            if (!is_array($body) || !is_string($body['name'] ?? null)) {
                return Response::error('Please give your name', 422);
            }

            // Save it for $form->tenantId. TenantContext is set to the same tenant.
            return Response::json(['received' => true], 201);
        },
    ]];
}
```

The route must be POST, at exactly `/api/<your slug>/forms/<name>/{key}`, with `<name>` matching `[a-z0-9][a-z0-9-]{0,62}`, and with no `requiredPermission` or `requiredRole`. Anything else isn't registered, and the reason is logged.

On a host older than core 0.2.26 the route is never public: 0.2.11 and later refuse to register it, and anything before that keeps it private. To refuse to load there at all, declare the core version with `PluginRequirementsInterface::getCoreConstraint()`.

## What the host does before your handler

In this order. A refusal never reaches your handler.

| Check | Refusal |
|---|---|
| Removes `Cookie`, `Authorization` and `X-Tenant-Id`. No session is read, and any `Set-Cookie` you return is dropped | |
| 20 requests an hour from one address, for this form | 429 with `Retry-After` |
| Body up to 64 KiB | 413 |
| `Content-Type: application/json` only, so no file uploads | 415 |
| The key: live, and issued for this plugin and this form | 404, the same for every reason |
| `Origin`: on the key's list or the instance's `CORS_ALLOWED_ORIGINS` | 403 |
| The instance's bot check is set up (see below) | 403 |
| 300 requests an hour for this form in this workspace, across all addresses | 429 with `Retry-After` |
| The bot token passes, checked server to server | 403 (with `Retry-After` if the provider can't be reached) |

A workspace whose subscription has lapsed answers 402 as for any other write, and form traffic counts against the workspace's request budget.

**The tenant comes only from the key.** A tenant in the body, a header, the query string or the Host is ignored. On a single-tenant instance it works the same way.

## What's still your job

- **Validate the content.** Nothing above looks inside the form.
- **Don't throw on bad input.** Return a 4xx. Your handler answers strangers. A throw gives them a 500, but it doesn't count towards quarantining your plugin, so nobody can switch your plugin off by sending it junk.
- **Remember the limits are per hour and shared.** A public form can still be filled in by a person with bad intentions. Don't trigger costly work per submission without your own thought about what that costs.

## The page

The page can live anywhere on the key's list of sites. It needs the key, which isn't a password: it's in the page's source. What protects the form is the Origin check, the bot check and the limits.

Read the site key from the host. It answers only to a live key, and the answer is readable only from an allowed site:

```js
const base = 'https://app.example.com/api/v1';
const key = 'THE_KEY_YOU_WERE_GIVEN';

const { data } = await (await fetch(`${base}/public/plugin-forms/${key}`)).json();
// data.accepting: false means this instance's bot check isn't set up yet.
// data.bot_check: { provider: 'turnstile', site_key: '...' }, or null if none is required.
```

Show the provider's widget with that site key, then send its token in `X-Whity-Bot-Token`:

```js
const res = await fetch(`${base}/arcline/forms/enquiry/${key}`, {
  method: 'POST',
  credentials: 'omit',
  headers: {
    'Content-Type': 'application/json',
    'X-Whity-Bot-Token': turnstileToken,
  },
  body: JSON.stringify({ name, message }),
});
```

The browser sends a preflight first, and the host answers it for allowed sites. If `fetch` itself fails, the browser wasn't allowed to read the answer: that is what a refusal looks like from a site that isn't on the list, and also what the per-IP limit, a size refusal and a wrong key look like, since those come before the host knows the site. Tell the visitor to try again later.

## Setting it up

**Operator, once for the instance.** In **Settings → Public forms → Bot check**, choose Cloudflare Turnstile and enter its site key and secret key. The secret is encrypted and can't be read back.

With no provider chosen, every public form is refused, so a form can't go public unprotected by accident. If something else in front of the instance stops bots, you can choose **Allow public forms with no bot check**. That only applies with no provider chosen: a provider with its secret or site key missing still refuses, and so does a provider that can't be reached.

hCaptcha and reCAPTCHA aren't offered yet. They're one entry each in `BotCheck::PROVIDERS`.

**Workspace admin, for each site.** In **Settings → Public forms**, issue a key for the form and list the sites that may send it. You need `public_form_keys:manage`, which every role holding `settings:write` gets when upgrading. The key is shown once and stored only as a hash. If it's lost, revoke it and issue another. Revoking takes effect at once and can't be undone.

## Public reads (SDK 1.69)

A plugin can also answer a GET from anybody holding a link: a public status page, a "where is my order" lookup, a public price list. Declare `'public' => 'read'`. It uses the same keys, the same tenant rule and the same allowed sites as a form. Needs SDK 1.69 and core 0.2.26.

```php
use Whity\Sdk\Http\PublicRead;

[
    'method'  => 'GET',
    'path'    => '/api/arcline/public/opening-hours/{key}',
    'public'  => 'read',
    'handler' => function (Request $request): Response {
        $read = PublicRead::from($request); // $read->tenantId, never the request's say-so
        return Response::json(['data' => $this->hours->forTenant($read->tenantId)]);
    },
]
```

The route must be GET, at exactly `/api/<your slug>/public/<name>/{key}`, with `<name>` as for a form and not the name of one of your forms, and with no `requiredPermission` or `requiredRole`. Anything else isn't registered, and the reason is logged. A workspace admin issues the key in **Settings → Public forms**, where the read is listed beside the forms.

**Off by default.** Public reads are served only when the operator sets `PLUGIN_PUBLIC_READS=on` for the instance. Until then the route is private: an anonymous request is asked to sign in, and a signed-in one still gets 404, because only the host's checks can open it.

What the host does before your handler, in this order:

| Check | Refusal |
|---|---|
| Removes `Cookie`, `Authorization` and `X-Tenant-Id`. No session is read, and any `Set-Cookie` you return is dropped | |
| 600 requests an hour from one address, for this read | 429 with `Retry-After` |
| The key: live, and issued for this plugin and this name | 404, the same for every reason |
| `Origin`, when the caller sends one: on the key's list or the instance's `CORS_ALLOWED_ORIGINS` | 403 |
| 6,000 requests an hour for this read in this workspace, across all addresses | 429 with `Retry-After` |

There is no bot check, since a read changes nothing. A link opened directly sends no `Origin` and is served; only an allowed site gets the headers that let its page read the answer with `fetch`. Send no custom headers from the page, so the browser doesn't need a preflight.

**Return only what anybody with the link may see.** Treat the key as visible to everyone who has the page. Don't put anything private behind a public read, don't throw on bad input (return a 4xx), and remember the handler's cost is multiplied by the limits above.

