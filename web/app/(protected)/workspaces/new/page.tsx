'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { invalidateWorkspaceCreateState, useWorkspaceCreateState } from '@/lib/use-workspace-create';
import { templatePickerPath } from '@/lib/workspace-template';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Card, CardContent } from '@whity/ui/card';
import { PageHeader } from '@whity/ui/page-header';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { Input } from '@/components/ui/input';
import { MAIN_COLOUR_SWATCHES } from '@/components/branding-main-colour';
import { useLanguageCode, useTranslation, type TranslateFn } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

/**
 * Create a workspace while signed in (WHIT-1169).
 *
 * From "Create workspace" at the foot of the workspace switcher (the
 * TenantSwitcher card) or Ctrl+K `/new workspace`. The person becomes its
 * owner and lands in it. It starts on the installation's default plan.
 *
 * No card for this screen yet (brand ask: brand-ask-workspace-owners.md §3);
 * built from TplWelcomeWorkspace's create step, inside the app.
 */

type FieldError = 'name_required' | 'name_too_long' | null;

const NAME_MAX = 120;

function fieldErrorMessage(t: TranslateFn, code: FieldError): string | null {
  if (code === 'name_required') return t('workspaceNew.name.required', 'Type a name for the workspace.');
  if (code === 'name_too_long') return t('workspaceNew.name.tooLong', 'Use {max} characters or fewer.', { max: NAME_MAX });
  return null;
}

export default function NewWorkspacePage() {
  const t = useTranslation('common');
  const uiLanguage = useLanguageCode();
  const router = useRouter();
  const { switchTenant } = useAuth();
  const { refresh: refreshNav } = useNavigation();
  const state = useWorkspaceCreateState();

  const [name, setName] = useState('');
  const [chosenLocale, setChosenLocale] = useState<string | null>(null);
  const locale = chosenLocale ?? (uiLanguage === 'ar' ? 'ar' : 'en');
  const [colour, setColour] = useState<string>(MAIN_COLOUR_SWATCHES[0]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldError, setFieldError] = useState<FieldError>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [limitReached, setLimitReached] = useState(false);

  const blocked = limitReached || state?.reason === 'limit_reached';
  const disabled = state?.reason === 'disabled';

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setFieldError(null);
    setFormError(null);

    const trimmed = name.trim();
    if (trimmed === '') {
      setFieldError('name_required');
      return;
    }
    if (trimmed.length > NAME_MAX) {
      setFieldError('name_too_long');
      return;
    }

    setSubmitting(true);
    try {
      const request: components['schemas']['WorkspaceCreateRequest'] = {
        name: trimmed,
        locale,
        // Whity Blue is the default: leave the setting empty for it.
        main_color: colour === MAIN_COLOUR_SWATCHES[0] ? '' : colour,
      };
      const { data, error, response } = await api.POST('/api/v1/workspaces', { body: request });
      const body = ((data ?? error ?? {}) as unknown) as {
        data?: { tenant_id?: number };
        details?: { code?: string };
      };

      if (response.status === 201 && typeof body.data?.tenant_id === 'number') {
        // Into the new workspace, as its owner; the switch re-reads /me.
        await switchTenant(body.data.tenant_id);
        await refreshNav();
        // After the switch, so the fresh answer is read with the new session.
        invalidateWorkspaceCreateState();
        // WHIT-1195: a new workspace starts from a template.
        router.push((await templatePickerPath()) ?? '/dashboard');
        return;
      }
      if (response.status === 422) {
        const code = body.details?.code;
        if (code === 'name_required' || code === 'name_too_long') {
          setFieldError(code);
        } else {
          setFormError(t('workspaceNew.error.choice', 'Pick a language and a colour from the choices.'));
        }
        return;
      }
      if (response.status === 409) {
        setLimitReached(true);
        invalidateWorkspaceCreateState();
        return;
      }
      if (response.status === 403) {
        invalidateWorkspaceCreateState();
        setFormError(t('workspaceNew.error.notAllowed', 'Creating workspaces isn’t possible on this Whity right now.'));
        return;
      }
      setFormError(t('workspaceNew.error.busy', 'We couldn’t make the workspace. Nothing was saved. Try again in a minute.'));
    } catch {
      setFormError(t('workspaceNew.error.busy', 'We couldn’t make the workspace. Nothing was saved. Try again in a minute.'));
    } finally {
      setSubmitting(false);
    }
  };

  const header = (
    <PageHeader
      title={t('workspaceNew.title', 'Create a workspace')}
      description={t(
        'workspaceNew.subtitle',
        'A new workspace for another company or team. You’ll be its owner, and you can invite people once it’s made.'
      )}
    />
  );

  if (disabled || blocked) {
    return (
      <div className="mx-auto max-w-2xl space-y-6" data-testid={disabled ? 'workspace-new-disabled' : 'workspace-new-limit'}>
        {header}
        <Alert>
          <AlertDescription>
            {disabled
              ? t('workspaceNew.disabled', 'This Whity doesn’t let people make their own workspaces. Ask its administrator.')
              : t(
                  'workspaceNew.limit',
                  'You already own {limit} workspaces, the most this Whity allows. Hand one over to someone else to make room.',
                  { limit: state?.limit ?? 0 }
                )}
          </AlertDescription>
        </Alert>
        <Button asChild variant="outline">
          <Link href="/dashboard">{t('workspaceNew.back', 'Back to your workspace')}</Link>
        </Button>
      </div>
    );
  }

  const nameError = fieldErrorMessage(t, fieldError);

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      {header}
      <Card>
        <CardContent className="pt-6">
          <form onSubmit={handleSubmit} className="space-y-5" data-testid="workspace-new-form" noValidate>
            {formError && (
              <Alert variant="destructive">
                <AlertDescription>{formError}</AlertDescription>
              </Alert>
            )}

            <div className="space-y-2">
              <label htmlFor="new-workspace-name" className="font-medium text-foreground">
                {t('workspaceNew.name.label', 'Workspace name')}
              </label>
              <Input
                id="new-workspace-name"
                value={name}
                maxLength={NAME_MAX + 20}
                autoComplete="organization"
                autoFocus
                aria-invalid={nameError !== null}
                aria-describedby={nameError !== null ? 'new-workspace-name-error' : 'new-workspace-name-hint'}
                onChange={(e) => {
                  setName(e.target.value);
                  if (fieldError) setFieldError(null);
                }}
                disabled={submitting}
              />
              {nameError !== null ? (
                <p id="new-workspace-name-error" className="text-sm text-destructive">
                  {nameError}
                </p>
              ) : (
                <p id="new-workspace-name-hint" className="text-sm text-muted-foreground">
                  {t('workspaceNew.name.hint', 'The company’s or team’s name. You can change it later.')}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <span className="block font-medium text-foreground">{t('workspaceNew.language.label', 'Main language')}</span>
              <ToggleGroup
                type="single"
                required
                label={t('workspaceNew.language.label', 'Main language')}
                value={locale}
                onChange={(next) => {
                  if (typeof next === 'string' && !submitting) setChosenLocale(next);
                }}
                options={[
                  { value: 'en', label: 'English' },
                  { value: 'ar', label: 'العربية' },
                ]}
              />
            </div>

            <fieldset className="space-y-2">
              <legend className="font-medium text-foreground">{t('workspaceNew.colour.label', 'Main colour')}</legend>
              <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('workspaceNew.colour.label', 'Main colour')}>
                {MAIN_COLOUR_SWATCHES.map((swatch, i) => (
                  <button
                    key={swatch}
                    type="button"
                    role="radio"
                    aria-checked={colour === swatch}
                    aria-label={
                      i === 0
                        ? t('workspaceNew.colour.default', 'Whity Blue (default)')
                        : t('workspaceNew.colour.option', 'Colour {n}', { n: i + 1 })
                    }
                    onClick={() => setColour(swatch)}
                    disabled={submitting}
                    className="size-10 rounded-control border border-border outline-offset-2 aria-checked:outline aria-checked:outline-2 aria-checked:outline-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    style={{ background: swatch }}
                  />
                ))}
              </div>
            </fieldset>

            <p className="text-sm text-muted-foreground">
              {t('workspaceNew.plan', 'It starts on the plan every new workspace gets here. You stay in your other workspaces.')}
            </p>

            <div className="flex flex-wrap gap-3">
              <Button type="submit" size="lg" disabled={submitting || state === null}>
                {submitting ? t('workspaceNew.creating', 'Creating…') : t('workspaceNew.create', 'Create workspace')}
              </Button>
              <Button asChild variant="outline" size="lg">
                <Link href="/dashboard">{t('workspaceNew.cancel', 'Cancel')}</Link>
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
