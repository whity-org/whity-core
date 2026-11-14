'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { ColorPicker } from '@whity/ui/color-picker';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { tokenValue, withToken, type ThemeTokens, type TokenSchema } from './model';

/**
 * The "Documents" tab: the theme's document tokens (E.44-E.47, 3.4's
 * letterhead layout), each control built from the schema the server sends —
 * a colour, a choice, a list of letterhead lines, or a switch. A token the
 * theme doesn't set shows Whity's standard, and clearing a colour hands it
 * back to the workspace's main colour.
 */
export function DocumentsTab({
  tokens,
  schema,
  resolved,
  readOnly,
  ar,
  onChange,
}: {
  tokens: ThemeTokens;
  schema: TokenSchema[];
  /** What the theme prints with, for the values it leaves to the standard. */
  resolved: ThemeTokens;
  readOnly: boolean;
  ar: boolean;
  onChange: (tokens: ThemeTokens) => void;
}) {
  const t = useTranslation('documents');
  const text = (o: { label: string; labelAr: string }) => (ar ? o.labelAr : o.label);
  const description = (o: TokenSchema) => (ar ? o.descriptionAr : o.description);

  const control = (token: TokenSchema) => {
    const own = tokenValue(tokens, token.key);
    const shown = own ?? tokenValue(resolved, token.key);
    switch (token.type) {
      case 'color':
        return (
          <ColorPicker
            label={text(token)}
            helperText={description(token)}
            value={typeof own === 'string' ? own : undefined}
            placeholder={typeof shown === 'string' ? shown : t('themes.docs.notSet', 'Not set')}
            clearable
            clearLabel={t('themes.docs.clear', 'Use the workspace colour')}
            disabled={readOnly}
            onChange={(color) => onChange(withToken(tokens, token.key, color ?? undefined))}
          />
        );
      case 'enum': {
        const values = token.values ?? [];
        const current = typeof shown === 'string' ? shown : values[0]?.value;
        return (
          <div className="grid gap-2">
            <span className="text-sm font-medium text-foreground">{text(token)}</span>
            <ToggleGroup
              label={text(token)}
              type="single"
              required
              value={current ?? null}
              options={values.map((v) => ({ value: v.value, label: text(v), disabled: readOnly }))}
              onChange={(next) => {
                if (typeof next === 'string') onChange(withToken(tokens, token.key, next));
              }}
              className="max-w-full flex-wrap"
            />
          </div>
        );
      }
      case 'list': {
        const chosen = Array.isArray(shown) ? (shown as string[]) : [];
        const values = token.values ?? [];
        return (
          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium text-foreground">{text(token)}</legend>
            <div className="flex flex-wrap gap-x-5 gap-y-3">
              {values.map((v) => (
                <label key={v.value} className="inline-flex items-center gap-2 text-sm text-foreground">
                  <Checkbox
                    checked={chosen.includes(v.value)}
                    disabled={readOnly}
                    onCheckedChange={(on) => {
                      // Kept in the schema's order, the order they print in.
                      const next = values
                        .map((x) => x.value)
                        .filter((x) => (x === v.value ? on === true : chosen.includes(x)));
                      onChange(withToken(tokens, token.key, next));
                    }}
                  />
                  {text(v)}
                </label>
              ))}
            </div>
          </fieldset>
        );
      }
      case 'boolean':
        return (
          <label className="flex items-start justify-between gap-4">
            <span className="grid gap-0.5">
              <span className="text-sm font-medium text-foreground">{text(token)}</span>
              {description(token) && <span className="text-xs text-muted-foreground">{description(token)}</span>}
            </span>
            <Switch
              checked={shown === true}
              disabled={readOnly}
              onCheckedChange={(on) => onChange(withToken(tokens, token.key, on))}
            />
          </label>
        );
      default:
        return null;
    }
  };

  const colours = schema.filter((s) => s.type === 'color' || s.key === 'accentUse');
  const letterhead = schema.filter((s) => s.key.startsWith('letterhead.'));
  const type = schema.filter((s) => !colours.includes(s) && !letterhead.includes(s));

  return (
    <div className="grid max-w-3xl gap-4">
      {[
        { id: 'colour', title: t('themes.docs.colour', 'Print colour'), tokens: colours },
        { id: 'letterhead', title: t('themes.docs.letterhead', 'Letterhead'), tokens: letterhead },
        { id: 'type', title: t('themes.docs.type', 'Title and text'), tokens: type },
      ]
        .filter((group) => group.tokens.length > 0)
        .map((group) => (
          <Card key={group.id}>
            <CardHeader>
              <CardTitle>
                <h2 className="text-base font-semibold">{group.title}</h2>
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-5">
              {group.tokens.map((token) => (
                <div key={token.key}>{control(token)}</div>
              ))}
            </CardContent>
          </Card>
        ))}
    </div>
  );
}
