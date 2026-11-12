'use client';

/**
 * The design system's DatePicker with this app's translations and language:
 * its own words, and month and weekday names in the interface language (digits
 * stay Western). See ./input.tsx, which does the same for `<Input type="date">`.
 */

import { useTranslation } from '@whity/features/i18n';
import { useInterfaceLocale } from '@/lib/interface-locale';
import { DatePicker as BaseDatePicker, type DatePickerProps } from '@whity/ui/date-picker';

export type { DatePickerProps } from '@whity/ui/date-picker';

export function DatePicker(props: DatePickerProps) {
  const t = useTranslation('common');
  const locale = useInterfaceLocale();

  return (
    <BaseDatePicker
      locale={locale}
      placeholder={t('ui.datePicker.placeholder', 'Select date…')}
      clearLabel={t('ui.datePicker.clear', 'Clear date')}
      todayLabel={t('ui.datePicker.today', 'Today')}
      clearButtonLabel={t('ui.datePicker.clearButton', 'Clear')}
      previousMonthLabel={t('ui.datePicker.previousMonth', 'Previous month')}
      nextMonthLabel={t('ui.datePicker.nextMonth', 'Next month')}
      tooltipTriggerLabel={t('ui.tooltipTrigger', 'More information')}
      {...props}
    />
  );
}
