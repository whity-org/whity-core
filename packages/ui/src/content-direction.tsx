"use client"

import type { ReactNode } from "react"
import { Direction } from "radix-ui"

import type { ContentLanguage } from "./localized-text"

/**
 * A block written in its own language inside the interface: an English form in
 * the Arabic app, an Arabic form in the English one (WHIT-1379). Sets `dir` and
 * `lang` on the block and tells Radix the same, so menus, radio groups and
 * selects inside it run in the block's direction; the page around it keeps the
 * interface's. Logical utilities and `rtl:` variants follow the nearest `dir`.
 */
export function ContentDirection({
  content,
  className,
  children,
}: {
  content: ContentLanguage
  className?: string
  children: ReactNode
}) {
  return (
    <Direction.Provider dir={content.dir}>
      <div dir={content.dir} lang={content.lang} className={className} data-content-direction={content.dir}>
        {children}
      </div>
    </Direction.Provider>
  )
}
