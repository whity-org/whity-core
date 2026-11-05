"use client"

import { Button } from "@whity/ui/button"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { pickText } from "./text"
import type { Problem } from "./builder-model"
import type { FieldDraft } from "./types"

/** The words for one problem, naming the question. */
export function useProblemText(fields: readonly FieldDraft[]): (problem: Problem) => string {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  return (problem) => {
    if (problem.index === null) return t("builder.problem.noName", "The form has no name.")
    const field = fields[problem.index]
    const number = problem.index + 1
    const title = pickText(field?.label, currentLanguage)
    const which = title === "" ? t("builder.problem.question", "Question {number}", { number }) : t("builder.problem.questionNamed", "Question {number}, “{title}”", { number, title })
    switch (problem.code) {
      case "noWording":
        return t("builder.problem.noWording", "{which} has no wording yet.", { which })
      case "blankChoice":
        return t("builder.problem.blankChoice", "{which} has a choice with no text.", { which })
      case "noChoices":
        return t("builder.problem.noChoices", "{which} needs at least one choice with text.", { which })
      case "noColumns":
        return t("builder.problem.noColumns", "{which} needs columns, each with a name.", { which })
      default:
        return problem.language === "ar"
          ? t("builder.problem.noArabic", "{which} has no Arabic yet.", { which })
          : t("builder.problem.noEnglish", "{which} has no English yet.", { which })
    }
  }
}

/** What needs doing, one button per item that takes you to the question. */
export function ProblemList({
  fields,
  problems,
  onJump,
}: {
  fields: readonly FieldDraft[]
  problems: readonly Problem[]
  onJump: (index: number) => void
}) {
  const t = useTranslation("forms")
  const text = useProblemText(fields)
  if (problems.length === 0) return null
  return (
    <div role="alert" className="space-y-2 rounded-card border border-destructive/40 bg-error-tint p-4 text-sm text-error-text">
      <p className="font-medium">{t("builder.problem.title", "Fix these first")}</p>
      <ul className="space-y-1">
        {problems.map((problem, i) => (
          <li key={i}>
            {problem.index === null ? (
              text(problem)
            ) : (
              <Button variant="link" className="h-auto whitespace-normal px-0 text-start text-error-text underline" onClick={() => onJump(problem.index as number)}>
                {text(problem)}
              </Button>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
