'use client';

/**
 * The workspace's one main colour (decision 2.8): six swatches and a custom
 * picker. Controlled: the Branding form owns the value and saves it with the
 * rest of the brand (TplAdminBranding has one Save for the page). The swatches
 * are shared with the welcome and new-workspace pages.
 */

import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { IconRotate } from '@tabler/icons-react';

const HEX_RE = /^#[0-9a-f]{6}$/;

export const MAIN_COLOUR_SWATCHES = ['#0961bb', '#b74c05', '#00792f', '#c53829', '#643b9a', '#edb417'];

/** Whity Blue, the platform's own main colour. */
export const WHITY_BLUE = MAIN_COLOUR_SWATCHES[0];

export interface MainColourPickerProps {
  /** 'tenant' or 'global'; only names the test ids. */
  scope: 'tenant' | 'global';
  /** The chosen colour, or null when none is set (the platform default, Whity Blue unless the platform set one). */
  value: string | null;
  disabled: boolean;
  onChange: (value: string | null) => void;
}

export function MainColourPicker({ scope, value, disabled, onChange }: MainColourPickerProps) {
  const t = useTranslation('admin');
  const selected = value?.toLowerCase() ?? null;

  return (
    <div className="space-y-2" data-testid={`branding-main-colour-${scope}`}>
      <p id={`branding-colour-label-${scope}`} className="text-xs font-semibold text-foreground">
        {t('branding.colour.label', 'Main colour')}
      </p>
      <div
        className="flex flex-wrap items-center gap-2"
        role="group"
        aria-labelledby={`branding-colour-label-${scope}`}
      >
        {MAIN_COLOUR_SWATCHES.map((swatch) => (
          <button
            key={swatch}
            type="button"
            disabled={disabled}
            aria-label={swatch}
            aria-pressed={(selected ?? WHITY_BLUE) === swatch}
            onClick={() => onChange(swatch)}
            className="size-8 rounded-control-sm border border-border outline-offset-2 aria-pressed:outline-2 aria-pressed:outline-foreground disabled:cursor-not-allowed disabled:opacity-50"
            style={{ background: swatch }}
            data-testid={`branding-colour-swatch-${swatch.slice(1)}`}
          />
        ))}
        <label className="ms-1 flex items-center gap-2 text-sm text-muted-foreground">
          <input
            type="color"
            value={selected ?? WHITY_BLUE}
            disabled={disabled}
            onChange={(e) => {
              const next = e.target.value.toLowerCase();
              if (HEX_RE.test(next)) onChange(next);
            }}
            className="h-8 w-10 cursor-pointer rounded-control-sm border border-border bg-transparent p-0.5 disabled:cursor-not-allowed"
            data-testid={`branding-colour-custom-${scope}`}
          />
          {t('branding.colour.custom', 'Custom')}
        </label>
        <span dir="ltr" className="ms-auto font-mono text-sm text-foreground" data-testid={`branding-colour-value-${scope}`}>
          {selected ?? t('branding.colour.default', 'Whity Blue (default)')}
        </span>
      </div>
      <p className="text-xs text-muted-foreground">
        {t(
          'branding.colour.description',
          'The label on it is white or near-black, whichever reaches 4.5:1. The tint, the colour on dark and the plane are derived from it.'
        )}
      </p>
      {selected !== null && !disabled && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => onChange(null)}
          className="gap-1.5 text-muted-foreground"
          data-testid={`branding-colour-reset-${scope}`}
        >
          <IconRotate className="size-4" aria-hidden />
          {t('branding.colour.reset', 'Reset')}
        </Button>
      )}
    </div>
  );
}
