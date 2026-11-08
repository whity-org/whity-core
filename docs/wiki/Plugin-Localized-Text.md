# Translatable fields for plugins

Use a translatable field when the **data** a person writes exists in more than one language, such as a question, a product name or a definition. Interface wording, such as button labels, is a separate thing.

Needs SDK SDK-MINOR. The working example is [`plugins/Glossary`](../../plugins/Glossary/GlossaryPlugin.php).

## The shape

A translatable value is one object with the languages it has:

```json
{ "ar": "فاتورة", "en": "Invoice" }
```

Arabic and English are the two languages. Either can be missing, because a person may not have translated it yet. `{}` means nothing has been written.

## Three steps

### 1. Declare the field

Use `LocalizedText::schema()` for the property in your route's `schema`:

```php
use Whity\Sdk\I18n\LocalizedText;

'GlossaryTermRequest' => [
    'type' => 'object',
    'required' => ['code', 'term'],
    'properties' => [
        'code' => ['type' => 'string', 'maxLength' => 64],
        'term' => LocalizedText::schema(),
        'definition' => LocalizedText::schema(),
    ],
],
```

It returns a plain array, so you can add to it: `LocalizedText::schema() + ['nullable' => true]`.

The array holds the marker `x-whity-localized-text: true`, which is how the host recognizes the field. Prefer the method to writing the marker by hand. The constant is `LocalizedText::MARKER`.

Declare it on both the row a list returns and the body a create or update takes. The list reads the first and the form reads the second.

### 2. Store it

Keep the object as JSON in a `TEXT` column, which works on PostgreSQL and on SQLite:

```php
$pdo->exec('CREATE TABLE IF NOT EXISTS glossary_terms (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    code VARCHAR(64) NOT NULL,
    term TEXT NOT NULL,
    definition TEXT NOT NULL
)');
```

Encode with `JSON_UNESCAPED_UNICODE` so the Arabic stays readable in the database.

### 3. Check it before saving

`LocalizedText::normalize()` trims the text and drops blank languages, because the form sends both languages whether or not both were filled in. It returns `null` for anything that isn't this shape.

```php
$term = LocalizedText::normalize($body['term'] ?? null);
if ($term === null || $term === []) {
    return Response::error('term needs text in at least one language', 400);
}
```

An empty array is a valid result, so a required field checks for it, as above. Length limits are yours to set: count each language with `mb_strlen()`.

When a value is empty, return `{}` and not `[]`. PHP encodes an empty array as a JSON list, so cast it: `(object) $value`.

## What the screens do

With the field declared, a `screen: 'crud'` feature needs nothing more:

| Where | What people see |
| --- | --- |
| List and record | The reader's language, then the other language. If neither has text, "Untranslated". |
| Create and edit form | Both languages side by side. A required field needs at least one. |

The screen goes by the page direction: right-to-left reads Arabic first, and left-to-right reads English first.

`resource.titleField` has to name a plain text or number column. A translatable field is an object, so a row titled by one shows as its id (`#12`). The example gives each term a plain `code` for this reason.

`screen: 'blocks'` doesn't read the marker. Return the text you want shown from your route.

## Showing it from your own code

For anything the screens don't draw, such as a notification or a document, pick the text with `LocalizedText::resolve()`:

```php
$label = LocalizedText::resolve($term, 'ar') ?? $code;
```

It follows the same order as the screens: the language you ask for, then the other one, and `null` when there is no text at all. `ar-JO` counts as `ar`, and any language other than Arabic reads English first. You decide which language to pass.

## Testing

`LocalizedText::isSchema($node)` applies the same test as the screens, so a test can check that a route's field will be drawn as translatable. See `tests/Integration/GlossaryPluginRealEngineTest.php`.
