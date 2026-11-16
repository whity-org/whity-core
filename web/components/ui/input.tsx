'use client';

/** The design system's Input with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@whity/features/i18n';
import { useInterfaceLocale } from '@/lib/interface-locale';
import { Input as BaseInput } from '@whity/ui/input';

export type { InputProps } from '@whity/ui/input';

export function Input(props: React.ComponentProps<typeof BaseInput>) {
  const t = useTranslation('common');
  const locale = useInterfaceLocale();
  // One sentence for the translator; the part inside <0>…</0> is drawn as a link.
  const [before = '', browse = '', after = ''] = t(
    'ui.input.dropzonePrompt',
    '<0>Click to browse</0> or drag and drop files',
  ).split(/<\/?0>/);

  return (
    <BaseInput
      showPasswordLabel={t('ui.input.showPassword', 'Show password')}
      hidePasswordLabel={t('ui.input.hidePassword', 'Hide password')}
      decrementLabel={t('ui.input.decrement', 'Decrease value')}
      incrementLabel={t('ui.input.increment', 'Increase value')}
      tooltipTriggerLabel={t('ui.tooltipTrigger', 'More information')}
      datePickerLabels={{
        locale,
        placeholder: t('ui.datePicker.placeholder', 'Select date…'),
        clear: t('ui.datePicker.clear', 'Clear date'),
        today: t('ui.datePicker.today', 'Today'),
        clearButton: t('ui.datePicker.clearButton', 'Clear'),
        previousMonth: t('ui.datePicker.previousMonth', 'Previous month'),
        nextMonth: t('ui.datePicker.nextMonth', 'Next month'),
      }}
      multipleFilesHint={t('ui.input.uploadMultiple', 'Upload multiple files')}
      singleFileHint={t('ui.input.uploadSingle', 'Upload single file')}
      dropzonePrompt={
        <>
          {before}
          <span className="text-primary hover:underline">{browse}</span>
          {after}
        </>
      }
      {...props}
    />
  );
}
