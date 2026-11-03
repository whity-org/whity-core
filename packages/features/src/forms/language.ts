import { useContext } from "react"

import { LanguageContext } from "../i18n/LanguageProvider"

/**
 * The language the person reads in, or null when no provider is above (a test, a
 * story). Unlike `useCurrentLanguage` it never throws: these screens fall back to
 * English text, the way `useTranslation` does.
 */
export function useCurrentLanguage(): { currentLanguage: string | null } {
  return { currentLanguage: useContext(LanguageContext)?.currentLanguage ?? null }
}
