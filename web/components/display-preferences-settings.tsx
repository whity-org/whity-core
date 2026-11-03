'use client';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useDisplayPreferences, type TextSize } from '@whity/ui/display-preferences';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';

/** Text size and Show technical details (W.3). Moves into the Preferences tab with U04. */
export function DisplayPreferencesSettings() {
  const t = useTranslation('auth');
  const { textSize, showTechnicalDetails, update } = useDisplayPreferences();

  return (
    <Card data-testid="display-preferences">
      <CardHeader>
        <CardTitle>{t('settings.display.title', 'Display')}</CardTitle>
        <CardDescription>{t('settings.display.description', 'How Whity looks for you, on every device.')}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        <div className="grid gap-2">
          <span className="text-sm font-medium">
            {t('settings.display.textSize', 'Text size')}
          </span>
          <ToggleGroup
            required
            label={t('settings.display.textSize', 'Text size')}
            value={textSize}
            onChange={(value) => update({ textSize: value as TextSize })}
            options={[
              { value: 'standard', label: t('settings.display.textSize.standard', 'Standard') },
              { value: 'larger', label: t('settings.display.textSize.larger', 'Larger') },
            ]}
          />
        </div>
        <label className="flex items-start justify-between gap-4">
          <span className="grid gap-0.5">
            <span className="text-sm font-medium">{t('settings.display.technical', 'Show technical details')}</span>
            <span className="text-xs text-muted-foreground">
              {t('settings.display.technical.help', 'Show permission codes, ids and error details where they apply.')}
            </span>
          </span>
          <Switch
            checked={showTechnicalDetails}
            onCheckedChange={(checked) => update({ showTechnicalDetails: checked })}
          />
        </label>
      </CardContent>
    </Card>
  );
}
