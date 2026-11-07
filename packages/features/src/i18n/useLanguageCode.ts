'use client'

import { useContext } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * The reader's language code, or null before one resolves or when no
 * LanguageProvider is mounted. For choosing which of a stored thing's names to
 * show (#821), where throwing outside a provider would take a screen down for a
 * cosmetic choice.
 */
export function useLanguageCode(): string | null {
  return useContext(LanguageContext)?.currentLanguage ?? null
}
