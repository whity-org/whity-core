"use client"

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react"
import { Button } from "@whity/ui/button"
import { cn } from "@whity/ui/utils"

import { useTranslation } from "../i18n"

export type FormLanguage = "en" | "ar"
export type LanguageView = FormLanguage | "both"

const ALL: readonly FormLanguage[] = ["en", "ar"]

export interface FormLanguageState {
  /** The languages this form is written in. At least one. */
  enabled: readonly FormLanguage[]
  /** The ones the fields show right now: the enabled ones, narrowed by the view. */
  shown: readonly FormLanguage[]
  view: LanguageView
  setView: (view: LanguageView) => void
  setEnabled: (language: FormLanguage, on: boolean) => void
}

const FormLanguageContext = createContext<FormLanguageState>({
  enabled: ALL,
  shown: ALL,
  view: "both",
  setView: () => {},
  setEnabled: () => {},
})

export function useFormLanguages(): FormLanguageState {
  return useContext(FormLanguageContext)
}

const storageKey = (formId: number) => `whity:form-languages:${formId}`

function readEnabled(formId: number): readonly FormLanguage[] {
  try {
    const raw = window.localStorage.getItem(storageKey(formId))
    const parsed: unknown = raw === null ? null : JSON.parse(raw)
    if (Array.isArray(parsed)) {
      const kept = ALL.filter((language) => parsed.includes(language))
      if (kept.length > 0) return kept
    }
  } catch {
    // Private windows and blocked storage land here; both languages is the safe answer.
  }
  return ALL
}

/**
 * Which languages a form is written in, and which of them the fields show.
 *
 * Switching a language off only stops asking for it: its inputs go away, and a
 * language left empty is no longer flagged. Text already written stays on the
 * form, and the server keeps no such setting, so the choice is this browser's.
 */
export function FormLanguageProvider({ formId, children }: { formId: number; children: React.ReactNode }) {
  const [enabled, setEnabledState] = useState<readonly FormLanguage[]>(ALL)
  const [view, setViewState] = useState<LanguageView>("both")

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reading browser storage once it exists
    setEnabledState(readEnabled(formId))
  }, [formId])

  const setEnabled = useCallback(
    (language: FormLanguage, on: boolean) => {
      setEnabledState((current) => {
        const next = ALL.filter((l) => (l === language ? on : current.includes(l)))
        if (next.length === 0) return current
        try {
          window.localStorage.setItem(storageKey(formId), JSON.stringify(next))
        } catch {
          // The choice just does not outlive the page.
        }
        return next
      })
      setViewState("both")
    },
    [formId]
  )

  const value = useMemo<FormLanguageState>(() => {
    const shown = view === "both" ? enabled : enabled.filter((l) => l === view)
    return { enabled, shown: shown.length > 0 ? shown : enabled, view, setView: setViewState, setEnabled }
  }, [enabled, view, setEnabled])

  return <FormLanguageContext.Provider value={value}>{children}</FormLanguageContext.Provider>
}

/** One selector for every English and Arabic field on the screen. */
export function LanguageBar({ className }: { className?: string }) {
  const t = useTranslation("forms")
  const { enabled, view, setView } = useFormLanguages()
  if (enabled.length < 2) return null
  const options: Array<{ value: LanguageView; label: string }> = [
    { value: "en", label: t("builder.lang.en", "English") },
    { value: "ar", label: t("builder.lang.ar", "Arabic") },
    { value: "both", label: t("builder.lang.both", "Both") },
  ]
  return (
    <div role="radiogroup" aria-label={t("builder.lang.edit", "Language you are writing in")} className={cn("flex gap-1 rounded-control bg-muted p-1", className)}>
      {options.map((option) => (
        <Button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={view === option.value}
          variant={view === option.value ? "default" : "ghost"}
          className="flex-1"
          onClick={() => setView(option.value)}
        >
          {option.label}
        </Button>
      ))}
    </div>
  )
}
