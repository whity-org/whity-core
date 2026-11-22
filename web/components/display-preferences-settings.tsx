'use client';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useDisplayPreferences, type TextSize } from '@whity/ui/display-preferences';
import type { MotionMode } from '@whity/ui/lottie';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { useThemeMode } from '@/lib/theme-mode-context';

/** Text size, Show technical details (W.3) and Animations (LT.4). Moves into the Preferences tab with U04. */
export function DisplayPreferencesSettings() {
  const t = useTranslation('auth');
  const { textSize, showTechnicalDetails, animations, contrast, update } = useDisplayPreferences();
  // Light or dark lives in this browser (lib/theme-mode-context.tsx), unlike the
  // rest of this card, which is stored on the account; the card says so.
  const { preference: theme, setPreference: setTheme } = useThemeMode();
  const motionOptions: { value: MotionMode; label: string; help: string }[] = [
    {
      value: 'full',
      label: t('settings.display.animations.full', 'Full'),
      help: t('settings.display.animations.full.help', 'Moments play once. Everyday ones take about a second; first-time ones up to three.'),
    },
    {
      value: 'calm',
      label: t('settings.display.animations.calm', 'Calm'),
      help: t('settings.display.animations.calm.help', 'Pictures fade in at their end. Nothing moves across the screen.'),
    },
    {
      value: 'off',
      label: t('settings.display.animations.off', 'Off'),
      help: t('settings.display.animations.off.help', 'Pictures show still. Waiting shows a still picture and the words.'),
    },
  ];

  return (
    <Card data-testid="display-preferences">
      <CardHeader>
        <CardTitle>{t('settings.display.title', 'Display')}</CardTitle>
        <CardDescription>{t(
            'settings.display.description',
            'Text size, technical details and animations follow you to every device. Light or dark is saved on this device.',
          )}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        <div className="grid gap-2" data-testid="display-theme">
          <span className="text-sm font-medium">{t('settings.display.theme', 'Light or dark')}</span>
          <ToggleGroup
            required
            label={t('settings.display.theme', 'Light or dark')}
            value={theme}
            onChange={(value) => setTheme(value as typeof theme)}
            options={[
              { value: 'light', label: t('settings.display.theme.light', 'Light') },
              { value: 'dark', label: t('settings.display.theme.dark', 'Dark') },
              { value: 'system', label: t('settings.display.theme.system', 'Same as this device') },
            ]}
          />
          <span className="text-xs text-muted-foreground">
            {t('settings.display.theme.help', 'Saved on this device only.')}
          </span>
        </div>
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
            <span className="text-sm font-medium">{t('settings.display.contrast', 'Higher contrast')}</span>
            <span className="text-xs text-muted-foreground">
              {t('settings.display.contrast.help', 'Darker text and clearer edges, whatever the workspace’s theme.')}
            </span>
          </span>
          <Switch
            checked={contrast === 'high'}
            onCheckedChange={(checked) => update({ contrast: checked ? 'high' : 'standard' })}
          />
        </label>
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
        <div className="grid gap-2" data-testid="display-animations">
          <span id="display-animations-label" className="text-sm font-medium">
            {t('settings.display.animations', 'Animations')}
          </span>
          <RadioGroup
            aria-labelledby="display-animations-label"
            value={animations}
            onValueChange={(value) => update({ animations: value as MotionMode })}
          >
            {motionOptions.map((option) => (
              <label
                key={option.value}
                className="flex cursor-pointer items-start gap-3 rounded-control border border-border p-3 has-[[data-state=checked]]:border-primary"
              >
                <RadioGroupItem value={option.value} className="mt-0.5" />
                <span className="grid gap-0.5">
                  <span className="text-sm font-medium">{option.label}</span>
                  <span className="text-xs text-muted-foreground">{option.help}</span>
                </span>
              </label>
            ))}
          </RadioGroup>
          <span className="text-xs text-muted-foreground">
            {t('settings.display.animations.reduced', 'If your device is set to reduce motion, Whity uses Off.')}
          </span>
        </div>
      </CardContent>
    </Card>
  );
}
