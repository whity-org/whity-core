"use client"

import { useMemo, useRef, useState } from "react"
import { Button } from "@whity/ui/button"
import { Card } from "@whity/ui/card"
import { Icon } from "@whity/ui/icon"
import { cn } from "@whity/ui/utils"

import { useTranslation } from "../i18n"
import { bannerArt, bannerOptions, newSeed, svgUrl, type BannerStyle } from "./banner-art"
import { FormHeader, useBrandColour } from "./form-header"
import type { FormBannerValue } from "./types"

/** Accepted by the server, decided there by the bytes; this only narrows the file picker. */
const ACCEPT = "image/png,image/jpeg,image/webp"
const MAX_BYTES = 5 * 1024 * 1024

/**
 * Choosing the picture at the top of a form (WHIT-944): none, one of six drawn from
 * the workspace's main colour, or an uploaded picture. "Show new options" draws six
 * more. The preview above is the form's top as people will see it, with its title,
 * whose colour is chosen to stay readable on the picture.
 */
export function BannerPicker({
  value,
  title,
  description,
  readOnly,
  onChange,
  onUpload,
}: {
  value: FormBannerValue | null
  title: string
  description: string
  readOnly: boolean
  onChange: (next: FormBannerValue | null) => void
  onUpload?: (file: File) => Promise<boolean>
}) {
  const t = useTranslation("forms")
  const brand = useBrandColour()
  const [start, setStart] = useState(() => (value?.kind === "pattern" ? value.seed : 20261107))
  const [uploading, setUploading] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const styleName: Record<BannerStyle, string> = {
    gradient: t("builder.banner.style.gradient", "Gradient"),
    waves: t("builder.banner.style.waves", "Waves"),
    dots: t("builder.banner.style.dots", "Dots"),
    stripes: t("builder.banner.style.stripes", "Stripes"),
    circles: t("builder.banner.style.circles", "Circles"),
    mesh: t("builder.banner.style.mesh", "Facets"),
  }

  // The chosen pattern stays among the options, in its own style's place.
  const options = useMemo(() => {
    const drawn = bannerOptions(start)
    return value?.kind === "pattern"
      ? drawn.map((option) => (option.style === value.style ? { style: option.style, seed: value.seed } : option))
      : drawn
  }, [start, value])

  const isChosen = (option: { style: string; seed: number }) =>
    value?.kind === "pattern" && value.style === option.style && value.seed === option.seed

  async function upload(file: File) {
    setProblem(null)
    if (!ACCEPT.split(",").includes(file.type)) {
      setProblem(t("builder.banner.wrongType", "Use a PNG, JPEG or WebP picture."))
      return
    }
    if (file.size > MAX_BYTES) {
      setProblem(t("builder.banner.tooBig", "The picture must be 5 MB or smaller."))
      return
    }
    if (onUpload === undefined) return
    setUploading(true)
    await onUpload(file)
    setUploading(false)
  }

  return (
    <div className="space-y-3">
      <div>
        <p className="text-sm font-medium" id="form-banner-label">
          {t("builder.banner.label", "Banner")}
        </p>
        <p className="text-xs text-muted-foreground">
          {t(
            "builder.banner.hint",
            "A picture at the top of the form. Choose one made from your brand colour, or upload your own. The title's colour is chosen so it stays easy to read."
          )}
        </p>
      </div>

      <Card aria-label={t("builder.banner.preview", "Preview")} data-testid="banner-preview">
        <FormHeader title={title} description={description} banner={value} titleAs="h3" />
      </Card>

      <div role="radiogroup" aria-labelledby="form-banner-label" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Tile
          label={t("builder.banner.none", "No banner")}
          checked={value === null}
          disabled={readOnly}
          onSelect={() => onChange(null)}
        >
          <span className="grid h-full place-items-center bg-muted text-sm text-muted-foreground">
            {t("builder.banner.none", "No banner")}
          </span>
        </Tile>
        {options.map((option) => {
          const art = bannerArt(brand, option.style, option.seed)
          return (
            <Tile
              key={`${option.style}-${option.seed}`}
              label={styleName[option.style]}
              checked={isChosen(option)}
              disabled={readOnly}
              onSelect={() => onChange({ kind: "pattern", style: option.style, seed: option.seed })}
            >
              <span className="block h-full bg-cover bg-center" style={{ backgroundImage: svgUrl(art.svg) }} />
            </Tile>
          )
        })}
        {value?.kind === "image" && value.image_url !== undefined && (
          <Tile label={t("builder.banner.yours", "Your picture")} checked disabled={readOnly} onSelect={() => undefined}>
            <span className="block h-full bg-cover bg-center" style={{ backgroundImage: `url("${value.image_url}")` }} />
          </Tile>
        )}
      </div>

      {!readOnly && (
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={() => setStart(newSeed())}>
            <Icon name="sync" size={16} aria-hidden />
            {t("builder.banner.more", "Show new options")}
          </Button>
          {onUpload !== undefined && (
            <>
              <Button type="button" variant="outline" disabled={uploading} onClick={() => fileInput.current?.click()}>
                <Icon name="upload" size={16} aria-hidden />
                {uploading ? t("builder.banner.uploading", "Uploading…") : t("builder.banner.upload", "Upload a picture")}
              </Button>
              <input
                ref={fileInput}
                type="file"
                accept={ACCEPT}
                className="sr-only"
                tabIndex={-1}
                aria-label={t("builder.banner.upload", "Upload a picture")}
                data-testid="banner-file"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  event.target.value = ""
                  if (file !== undefined) void upload(file)
                }}
              />
            </>
          )}
        </div>
      )}
      {problem !== null && (
        <p className="text-sm text-destructive" role="alert">
          {problem}
        </p>
      )}
    </div>
  )
}

function Tile({
  label,
  checked,
  disabled,
  onSelect,
  children,
}: {
  label: string
  checked: boolean
  disabled: boolean
  onSelect: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={checked}
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onSelect}
      className={cn(
        "relative h-16 overflow-clip rounded-control border border-border outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed",
        checked && "border-primary ring-2 ring-primary ring-offset-2 ring-offset-background"
      )}
    >
      {children}
      {checked && (
        <span className="absolute end-1 top-1 grid size-6 place-items-center rounded-full bg-primary text-primary-foreground">
          <Icon name="check" size={14} aria-hidden />
        </span>
      )}
    </button>
  )
}
