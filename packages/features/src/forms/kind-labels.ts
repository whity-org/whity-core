import { useTranslation } from "../i18n"
import type { FieldKind } from "./types"

/** The plain-words name of each kind of question. */
export function useKindLabel(): (kind: string) => string {
  const t = useTranslation("forms")
  return (kind: string) => {
    switch (kind as FieldKind) {
      case "text":
        return t("builder.kind.text", "Short text")
      case "textarea":
        return t("builder.kind.textarea", "Long text")
      case "number":
        return t("builder.kind.number", "Number")
      case "date":
        return t("builder.kind.date", "Date")
      case "select":
        return t("builder.kind.select", "Choose one")
      case "multiselect":
        return t("builder.kind.multiselect", "Choose several")
      case "checkbox":
        return t("builder.kind.checkbox", "Checkbox")
      case "file":
        return t("builder.kind.file", "File")
      case "profile_ref":
        return t("builder.kind.profile_ref", "Person")
      case "ou_ref":
        return t("builder.kind.ou_ref", "Unit")
      case "repeating":
        return t("builder.kind.repeating", "Table of rows")
      case "signature":
        return t("builder.kind.signature", "Signature")
      default:
        return kind
    }
  }
}
