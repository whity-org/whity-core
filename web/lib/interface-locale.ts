'use client';

import * as i18n from '@whity/features/i18n';

/**
 * The interface language as a formatting locale (`ar`, `en`), for the design
 * system's date picker. Read through the namespace so a test that mocks
 * `@whity/features/i18n` with only `useTranslation` still renders the shared
 * Input and DatePicker wrappers: the hook is chosen once, when the module
 * loads, so every render calls the same one.
 */
export const useInterfaceLocale: () => string | undefined =
  typeof i18n.useFormattingLocale === 'function' ? i18n.useFormattingLocale : () => undefined;
