'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';

/**
 * While a template's example customers and bookings are in the workspace
 * (WHIT-1195), a slim note on the dashboard with Remove examples: one click,
 * one confirmation, and only the examples go (bookings cancelled, contacts
 * archived). Shown to people who may set the workspace up.
 *
 * No card yet: brand ask brand-ask-workspace-templates.md §3.
 */
export function TemplateExamples() {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [version, setVersion] = useState(0);
  const [confirming, setConfirming] = useState(false);
  const { data: count } = useFetch(async () => {
    const { data } = await api.GET('/api/v1/workspace/templates');
    return data?.data.examples ?? 0;
  }, [version]);

  if (!count) {
    return null;
  }

  async function remove() {
    const { data, response } = await api.DELETE('/api/v1/workspace/templates/examples');
    if (!response.ok) {
      addToast(t('templates.examplesRemoveError', 'The examples could not be removed. Try again.'), 'error');
      return;
    }
    const failed = data?.data.failed ?? 0;
    addToast(
      failed > 0
        ? t('templates.examplesPartly', 'Most examples were removed. Try again for the rest.')
        : t('templates.examplesRemoved', 'The examples are gone.'),
      failed > 0 ? 'error' : 'success',
    );
    setVersion((v) => v + 1);
  }

  return (
    <Alert>
      <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
        <span>{t('templates.examplesBanner', 'Some customers and bookings are examples from your template.')}</span>
        <Button variant="outline" size="sm" onClick={() => setConfirming(true)}>
          {t('templates.examplesRemove', 'Remove examples')}
        </Button>
      </AlertDescription>
      <ConfirmAction
        open={confirming}
        onOpenChange={setConfirming}
        title={t('templates.examplesConfirmTitle', 'Remove the examples?')}
        description={t(
          'templates.examplesConfirmBody',
          'Example bookings are cancelled and example customers archived. Nothing you added yourself changes.',
        )}
        confirmLabel={t('templates.examplesRemove', 'Remove examples')}
        cancelLabel={t('templates.back', 'Back')}
        onConfirm={remove}
      />
    </Alert>
  );
}
