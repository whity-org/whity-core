"use client"

import { useEffect, useMemo, useState } from "react"
import { CardDescription, CardHeader, CardTitle } from "@whity/ui/card"
import { cn } from "@whity/ui/utils"
import type { ContentLanguage } from "@whity/ui/localized-text"

import { bannerArt, hexToRgb, svgUrl, type BannerStyle, type Rgb } from "./banner-art"
import type { FormBannerValue } from "./types"

/** Whity Blue: what the theme's primary is when nothing can be read from the page. */
const FALLBACK_BRAND: Rgb = [9, 97, 187]

/**
 * The workspace's main colour as the page draws it: the theme's `--primary`, which
 * the root layout sets from the workspace's branding, in light or dark mode. Read
 * through a canvas because the token may be written in any CSS colour syntax.
 */
export function useBrandColour(): Rgb {
  const [rgb, setRgb] = useState<Rgb>(FALLBACK_BRAND)

  useEffect(() => {
    const read = () => {
      const value = getComputedStyle(document.documentElement).getPropertyValue("--primary").trim()
      const parsed = value === "" ? null : (hexToRgb(value) ?? viaCanvas(value))
      if (parsed !== null) setRgb((was) => (was.join() === parsed.join() ? was : parsed))
    }
    read()
    // The theme switches by a class or attribute on <html>; follow it.
    const observer = new MutationObserver(read)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class", "style", "data-theme"] })
    return () => observer.disconnect()
  }, [])

  return rgb
}

function viaCanvas(colour: string): Rgb | null {
  try {
    const canvas = document.createElement("canvas")
    canvas.width = 1
    canvas.height = 1
    const context = canvas.getContext("2d")
    if (context === null) return null
    context.fillStyle = "#010203"
    context.fillStyle = colour
    // An unparsable colour leaves the sentinel in place.
    if (context.fillStyle === "#010203") return null
    context.fillRect(0, 0, 1, 1)
    const [r, g, b] = context.getImageData(0, 0, 1, 1).data
    return [r, g, b]
  } catch {
    return null
  }
}

/** The background and title colour for a banner, or null for none. */
export function useBannerLook(banner: FormBannerValue | null | undefined): { background: string; text: string; scrim: boolean } | null {
  const brand = useBrandColour()
  return useMemo(() => {
    if (banner == null) return null
    if (banner.kind === "image") {
      if (banner.image_url === undefined) return null
      // A picture can be any colour, so the title gets white over a dark scrim that
      // is darkest where the title sits: from 55% black down, over even a white
      // picture, white text is at least 4.6:1.
      return { background: `url("${banner.image_url.replace(/"/g, "%22")}")`, text: "#ffffff", scrim: true }
    }
    const art = bannerArt(brand, banner.style as BannerStyle, banner.seed)
    return { background: svgUrl(art.svg), text: art.text, scrim: false }
  }, [banner, brand])
}

/**
 * The top of a form as the person filling it in sees it: the banner with the title
 * over it, then the description. Without a banner it is the card's own header.
 * Rendered first in a `Card`, which drops its top padding for a `card-banner`.
 */
export function FormHeader({
  title,
  description,
  banner,
  titleAs = "h1",
  content,
}: {
  title: string
  description?: string | null
  banner?: FormBannerValue | null
  titleAs?: "h1" | "h2" | "h3"
  /** WHIT-1379: the form's own language and direction; without it each line finds its own. */
  content?: ContentLanguage
}) {
  const look = useBannerLook(banner)
  const Heading = titleAs
  const hasDescription = description != null && description !== ""
  const dir = content?.dir ?? "auto"
  const lang = content?.lang

  if (look === null) {
    return (
      <CardHeader dir={content?.dir} lang={lang}>
        <CardTitle>
          <Heading dir={dir} className="text-lg font-semibold">{title}</Heading>
        </CardTitle>
        {hasDescription && <CardDescription dir={dir} className="text-sm">{description}</CardDescription>}
      </CardHeader>
    )
  }

  return (
    <>
      <div
        data-slot="card-banner"
        data-testid="form-banner"
        dir={content?.dir}
        lang={lang}
        className="relative flex min-h-32 items-end bg-cover bg-center sm:min-h-44"
        style={{ backgroundImage: look.background }}
      >
        {look.scrim && (
          <span
            aria-hidden
            className="absolute inset-0"
            style={{ backgroundImage: "linear-gradient(to bottom, rgb(0 0 0 / 0.2), rgb(0 0 0 / 0.55) 40%, rgb(0 0 0 / 0.75))" }}
          />
        )}
        <Heading dir={dir} className="relative px-4 pt-8 pb-4 text-xl font-semibold text-balance sm:text-2xl" style={{ color: look.text }}>
          {title}
        </Heading>
      </div>
      {hasDescription && (
        <CardHeader dir={content?.dir} lang={lang}>
          <CardDescription dir={dir} className={cn("text-sm")}>{description}</CardDescription>
        </CardHeader>
      )}
    </>
  )
}
