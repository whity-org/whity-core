"use client"

import { Badge } from "@whity/ui/badge"
import { Input } from "@whity/ui/input"

import { useTranslation } from "../i18n"
import { useFormLanguages, type FormLanguage } from "./form-languages"

export interface BilingualValue {
  ar?: string
  en?: string
  [key: string]: string | undefined
}

/**
 * Text in the languages this form is written in, one input under another with
 * the language named on each, so it is always clear which one you are typing in.
 * Which languages show follows the language selector for the whole screen.
 */
export function BilingualField({
  id,
  label,
  description,
  value,
  onChange,
  disabled,
  className,
}: {
  id: string
  label?: string
  description?: string
  value: BilingualValue
  onChange: (next: BilingualValue) => void
  disabled?: boolean
  className?: string
}) {
  const t = useTranslation("forms")
  const { shown } = useFormLanguages()
  const names: Record<FormLanguage, string> = { en: t("builder.lang.en", "English"), ar: t("builder.lang.ar", "Arabic") }

  return (
    <div className={className}>
      {label !== undefined && <div className="text-sm font-medium">{label}</div>}
      {description !== undefined && <p className="text-xs text-muted-foreground">{description}</p>}
      <div className="mt-2 space-y-3">
        {shown.map((language) => {
          const text = value[language] ?? ""
          return (
            <div key={language} className="space-y-1">
              <div className="flex items-center justify-between gap-2">
                <label htmlFor={`${id}-${language}`} className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Badge variant="outline" className="font-mono uppercase">
                    {language}
                  </Badge>
                  {names[language]}
                </label>
                {text.trim() === "" && <span className="text-xs text-muted-foreground">{t("builder.lang.empty", "Empty")}</span>}
              </div>
              <Input
                id={`${id}-${language}`}
                dir={language === "ar" ? "rtl" : "ltr"}
                lang={language}
                disabled={disabled}
                value={text}
                onChange={(event) => onChange({ ...value, [language]: event.target.value })}
              />
            </div>
          )
        })}
      </div>
    </div>
  )
}
