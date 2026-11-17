'use client';

/**
 * My settings › Profile › Phone number (WHIT-1181): the person's own phone,
 * stored as E.164 on their account and theirs in every workspace. Booking
 * pages fill it in for them; nobody else can change it.
 */

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { Button } from '@whity/ui/button';
import { PhoneInput } from '@whity/ui/phone-input';
import { Skeleton } from '@whity/ui/skeleton';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';

export function MyPhoneSettings() {
  const t = useTranslation('auth');
  const language = useLanguageCode();
  const { addToast } = useToast();
  const [saved, setSaved] = useState<string | null | undefined>(undefined);
  const [draft, setDraft] = useState<{ value: string | null; valid: boolean } | null>(null);
  const [failed, setFailed] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api
      .GET('/api/v1/me/phone')
      .then(({ data, response }) => {
        if (cancelled) return;
        if (!response.ok || !data) {
          setFailed(true);
          return;
        }
        setSaved(data.data.phone ?? null);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (failed) {
    return <p className="text-sm text-muted-foreground">{t('settings.phone.loadFailed', 'Your phone number didn’t load. Refresh the page to try again.')}</p>;
  }
  if (saved === undefined) {
    return <Skeleton className="h-10 w-full max-w-md" />;
  }

  const save = async () => {
    if (!draft || !draft.valid || draft.value === saved) return;
    setSaving(true);
    setServerError(null);
    try {
      const { data, response } = await api.PUT('/api/v1/me/phone', { body: { phone: draft.value } });
      if (!response.ok || !data) {
        setServerError(t('settings.phone.invalid', 'Enter the number with its country code, such as +962 79 123 4567.'));
        return;
      }
      setSaved(data.data.phone ?? null);
      setDraft(null);
      addToast(
        data.data.phone
          ? t('settings.phone.saved', 'Phone number saved.')
          : t('settings.phone.removed', 'Phone number removed.'),
        'success'
      );
    } catch {
      addToast(t('settings.phone.saveFailed', 'Your phone number wasn’t saved. Try again.'), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="max-w-md space-y-4">
      <PhoneInput
        id="my-phone"
        key={saved ?? 'none'}
        label={t('settings.phone.label', 'Phone number')}
        defaultValue={saved}
        lang={language === 'ar' ? 'ar' : 'en'}
        disabled={saving}
        helperText={t('settings.phone.hint', 'Optional. Booking pages fill it in for you; workspaces see it only when you book.')}
        errorText={serverError ?? undefined}
        labels={{
          country: t('settings.phone.country', 'Country code'),
          other: t('settings.phone.otherCountry', 'Other country'),
          short: t('settings.phone.tooShort', 'This number is too short for {country}.', { country: '{country}' }),
          long: t('settings.phone.tooLong', 'This number is too long for {country}.', { country: '{country}' }),
          invalid: t('settings.phone.invalid', 'Enter the number with its country code, such as +962 79 123 4567.'),
        }}
        onChange={(value, valid) => {
          setServerError(null);
          setDraft({ value, valid });
        }}
      />
      {/* The field reports its number when it is left, which a click on Save does first. */}
      <Button type="button" variant="outline" onClick={save} disabled={saving || (draft !== null && !draft.valid)} data-testid="my-phone-save">
        {saving ? t('settings.phone.saving', 'Saving…') : t('settings.phone.save', 'Save phone number')}
      </Button>
    </div>
  );
}
