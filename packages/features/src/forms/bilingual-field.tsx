"use client"

import { BilingualInput, type BilingualInputProps } from "@whity/ui/bilingual-input"

import { useTranslation } from "../i18n"

/** The two-language input, with its language names and badges in the person's language. */
export function BilingualField(props: Omit<BilingualInputProps, "enLabel" | "arLabel" | "presentLabel" | "emptyLabel">) {
  const t = useTranslation("forms")
  return (
    <BilingualInput
      {...props}
      enLabel={t("builder.lang.en", "English")}
      arLabel={t("builder.lang.ar", "Arabic")}
      presentLabel={t("builder.lang.set", "Set")}
      emptyLabel={t("builder.lang.empty", "Empty")}
    />
  )
}
