"use client"

import { BilingualField } from "./bilingual-field"
import { Button } from "@whity/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@whity/ui/card"
import { IconButton } from "@whity/ui/icon-button"
import { Input } from "@whity/ui/input"
import { Switch } from "@whity/ui/switch"

import { useTranslation } from "../i18n"
import { useFormLanguages } from "./form-languages"
import { BannerPicker } from "./banner-picker"
import { useCurrentLanguage } from "./language"
import { pickText } from "./text"
import type { FormBannerValue, FormRow, FormSectionDef, LocalizedMap } from "./types"

/** The parts of a form the Settings tab edits, held together so one save bar covers them. */
export interface FormSettings {
  name: LocalizedMap
  /** What the person filling it in reads as its heading; empty shows the name. */
  title: LocalizedMap
  banner: FormBannerValue | null
  description: LocalizedMap
  submit_label: LocalizedMap
  reference_format: string
  sections: FormSectionDef[]
}

export function FormSettingsTab({
  form,
  settings,
  readOnly,
  publicBusy,
  onChange,
  onPublicLink,
  onCopyLink,
  onMemberAccess,
  onSignInRequired,
  onUploadBanner,
}: {
  form: FormRow
  settings: FormSettings
  readOnly: boolean
  publicBusy: boolean
  onChange: (next: FormSettings) => void
  onPublicLink: (enabled: boolean) => void
  onCopyLink: () => void
  onMemberAccess: (open: boolean) => void
  /** WHIT-920: ask visitors to sign in before they send the form. */
  onSignInRequired?: (required: boolean) => void
  /** Uploads a picture as the banner; resolves true when the server kept it. */
  onUploadBanner?: (file: File) => Promise<boolean>
}) {
  const t = useTranslation("forms")
  const { enabled, setEnabled } = useFormLanguages()
  const { currentLanguage } = useCurrentLanguage()
  const shownTitle =
    pickText(settings.title, currentLanguage) || pickText(settings.name, currentLanguage) || t("builder.untitled", "Untitled form")

  function setSection(index: number, patch: Partial<FormSectionDef>) {
    onChange({ ...settings, sections: settings.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)) })
  }

  function addSection() {
    const taken = new Set(settings.sections.map((s) => s.key))
    let n = settings.sections.length + 1
    while (taken.has(`section_${n}`)) n += 1
    onChange({ ...settings, sections: [...settings.sections, { key: `section_${n}`, title: {}, description: {} }] })
  }

  return (
    <div className="max-w-3xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>{t("builder.settings.languages", "Languages")}</CardTitle>
          <CardDescription>
            {t(
              "builder.settings.languages.body",
              "Choose the languages you write this form in. Switching one off hides its fields here and stops asking for it. Text already written stays on the form, and this choice is kept in this browser only."
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {(["en", "ar"] as const).map((language) => (
            <div key={language} className="flex items-center gap-3">
              <Switch
                id={`form-language-${language}`}
                checked={enabled.includes(language)}
                disabled={readOnly || (enabled.length === 1 && enabled.includes(language))}
                onCheckedChange={(on) => setEnabled(language, on)}
              />
              <label htmlFor={`form-language-${language}`} className="text-sm font-medium">
                {language === "en" ? t("builder.lang.en", "English") : t("builder.lang.ar", "Arabic")}
              </label>
            </div>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("builder.settings.shown", "What people filling it in see")}</CardTitle>
          <CardDescription>
            {t("builder.settings.shown.body", "The top of the form, on the signed-in page and on its public link.")}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          <BannerPicker
            value={settings.banner}
            title={shownTitle}
            description={pickText(settings.description, currentLanguage)}
            readOnly={readOnly}
            onChange={(banner) => onChange({ ...settings, banner })}
            onUpload={onUploadBanner}
          />
          <BilingualField
            id="form-title"
            label={t("builder.settings.title", "Title")}
            description={t("builder.settings.title.hint", "The heading people read. Leave it empty to show the form name.")}
            value={settings.title}
            onChange={(title) => onChange({ ...settings, title })}
            disabled={readOnly}
          />
          <BilingualField
            id="form-description"
            label={t("builder.settings.description", "Description")}
            description={t("builder.settings.description.hint", "Say who receives the form and what happens next.")}
            value={settings.description}
            onChange={(description) => onChange({ ...settings, description })}
            disabled={readOnly}
          />
          <BilingualField
            id="form-submit"
            label={t("builder.settings.submit", "Send button text")}
            description={t("builder.settings.submit.hint", "Name what happens, such as “Send application”.")}
            value={settings.submit_label}
            onChange={(submit_label) => onChange({ ...settings, submit_label })}
            disabled={readOnly}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("builder.settings.about", "About this form")}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-5">
          <BilingualField
            id="form-name"
            label={t("builder.settings.name", "Form name")}
            description={t("builder.settings.name.hint", "What your team calls it in lists and the inbox.")}
            value={settings.name}
            onChange={(name) => onChange({ ...settings, name })}
            disabled={readOnly}
          />
          <div className="space-y-1.5">
            <label htmlFor="form-reference" className="text-sm font-medium">
              {t("builder.settings.reference", "Reference number")}
            </label>
            <Input
              id="form-reference"
              dir="ltr"
              className="font-mono"
              value={settings.reference_format}
              disabled={readOnly}
              placeholder="IRB-{YYYY}-{SEQ:4}"
              onChange={(e) => onChange({ ...settings, reference_format: e.target.value })}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                "builder.settings.reference.hint",
                "Optional. Use {SEQ} once for the running number, and {YYYY}, {YY} or {MM} for the date. Leave empty for no reference."
              )}
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("builder.settings.sections", "Sections")}</CardTitle>
          <CardDescription>
            {t("builder.settings.sections.body", "A long form shows its sections as steps. Choose a question's section in the Questions tab.")}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {settings.sections.map((section, i) => (
            <div key={section.key} className="flex items-start gap-2 rounded-card border border-border p-3">
              <div className="min-w-0 flex-1 space-y-3">
                <BilingualField
                  id={`section-${i}-title`}
                  label={t("builder.settings.sectionTitle", "Section {number} title", { number: i + 1 })}
                  value={section.title}
                  onChange={(title) => setSection(i, { title })}
                  disabled={readOnly}
                  
                />
                <BilingualField
                  id={`section-${i}-description`}
                  label={t("builder.settings.sectionDescription", "Section {number} description", { number: i + 1 })}
                  value={section.description}
                  onChange={(description) => setSection(i, { description })}
                  disabled={readOnly}
                  
                />
              </div>
              {!readOnly && (
                <IconButton
                  icon="delete"
                  label={t("builder.settings.removeSection", "Remove section")}
                  onClick={() => onChange({ ...settings, sections: settings.sections.filter((_, j) => j !== i) })}
                />
              )}
            </div>
          ))}
          {!readOnly && (
            <Button variant="outline" onClick={addSection}>
              {t("builder.settings.addSection", "Add a section")}
            </Button>
          )}
        </CardContent>
      </Card>

      {form.open_to_members !== undefined && (
        <Card>
          <CardHeader>
            <CardTitle>{t("builder.settings.members", "Open to members")}</CardTitle>
            <CardDescription>
              {t("builder.settings.members.body", "Let people in this workspace fill in this form, even if they cannot manage or read forms. They see only this form and their own submissions.")}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex items-center gap-3">
              <Switch
                id="form-members"
                checked={form.open_to_members}
                disabled={readOnly || publicBusy || form.status !== "published"}
                onCheckedChange={onMemberAccess}
              />
              <label htmlFor="form-members" className="text-sm font-medium">
                {form.open_to_members ? t("builder.settings.members.on", "Open to members") : t("builder.settings.members.off", "Not open to members")}
              </label>
            </div>
            {form.status !== "published" && (
              <p className="text-xs text-muted-foreground">{t("builder.settings.members.publishFirst", "Open the form first to turn this on.")}</p>
            )}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>{t("builder.settings.link", "Anyone with the link")}</CardTitle>
          <CardDescription>
            {t("builder.settings.link.body", "Open the form to visitors who have no account. Only questions a visitor can answer work here.")}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center gap-3">
            <Switch
              id="form-public"
              checked={form.public_enabled}
              disabled={readOnly || publicBusy || form.status !== "published"}
              onCheckedChange={onPublicLink}
            />
            <label htmlFor="form-public" className="text-sm font-medium">
              {form.public_enabled ? t("builder.settings.link.on", "The link is open") : t("builder.settings.link.off", "The link is off")}
            </label>
          </div>
          {form.status !== "published" && (
            <p className="text-xs text-muted-foreground">{t("builder.settings.link.publishFirst", "Open the form first to turn its link on.")}</p>
          )}
          {form.public_requires_sign_in !== undefined && onSignInRequired !== undefined && (
            <div className="space-y-1">
              <div className="flex items-center gap-3">
                <Switch
                  id="form-public-sign-in"
                  checked={form.public_requires_sign_in}
                  disabled={readOnly || publicBusy}
                  onCheckedChange={onSignInRequired}
                />
                <label htmlFor="form-public-sign-in" className="text-sm font-medium">
                  {t("builder.settings.link.signIn", "People must sign in to send it")}
                </label>
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  "builder.settings.link.signIn.body",
                  "Only members and customers of this workspace can send it, under their own name. Then a reviewer can send it back to them for changes."
                )}
              </p>
            </div>
          )}
          {form.public_url !== null && form.public_enabled && (
            <div className="flex flex-wrap items-center gap-2">
              <code dir="ltr" className="min-w-0 flex-1 truncate rounded-control bg-muted px-3 py-2 text-xs">
                {form.public_url}
              </code>
              <Button variant="outline" onClick={onCopyLink}>
                {t("builder.settings.link.copy", "Copy link")}
              </Button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
