"use client"

import * as React from "react"

import { cn } from "./utils"
import { Icon, type IconName } from "./icon"

/**
 * The web app's tab bar on a phone (`PwNav`, PW.1–PW.3; M.2, CH.3).
 *
 * Under 640px the web app swaps its drawer for the phone app's five places:
 * Home, Inbox, the centre Create button, Chat and More. Labels always show
 * under the icons; counts sit on Inbox and Chat only, red, capped at 99+.
 * While the on-screen keyboard is open the bar steps aside so the page's own
 * button can ride on the keyboard (`useKeyboardOpen`).
 *
 * Chrome only: the composed wrapper decides the hrefs, labels and counts, and
 * which tab is current. From 640px up the bar is not rendered at all (`sm:hidden`),
 * so desktop layouts never reserve room for it.
 */

export interface PhoneTab {
  id: string
  label: string
  href: string
  icon: IconName
  /** Waiting count; shown only when above zero. */
  count?: number
  /** Accessible wording for the count ("3 waiting"). */
  countLabel?: string
  active?: boolean
}

export interface PhoneTabBarProps {
  /**
   * The two tabs before and the two after Create, in reading order. `null`
   * is an empty slot, so Create stays centred when a place is missing.
   */
  start: (PhoneTab | null)[]
  end: (PhoneTab | null)[]
  createLabel: string
  onCreate: () => void
  /** Accessible name of the bar (`Main`). */
  navLabel: string
  /** Next's `Link`, or anything taking `href`. Defaults to `<a>`. */
  linkComponent?: React.ElementType
  className?: string
}

/** Height of the bar above the home indicator, for anything docked above it. */
export const PHONE_TAB_BAR_HEIGHT = "4rem"

/**
 * True while an on-screen keyboard covers the bottom of the page.
 *
 * The visual viewport shrinks when a keyboard opens (with
 * `interactive-widget=resizes-content`, WHIT-1006, the layout viewport does
 * too), so the signal is a text field holding focus on a touch screen: that
 * is the only time a phone shows its keyboard. A hardware keyboard on a
 * laptop never hides the bar, because the bar only exists under 640px.
 */
export function useKeyboardOpen(): boolean {
  const [open, setOpen] = React.useState(false)
  React.useEffect(() => {
    if (typeof window === "undefined") return
    const coarse = window.matchMedia?.("(pointer: coarse)")
    const typing = (el: Element | null): boolean => {
      if (!(el instanceof HTMLElement)) return false
      if (el.isContentEditable) return true
      if (el instanceof HTMLTextAreaElement) return true
      if (!(el instanceof HTMLInputElement)) return false
      return !["checkbox", "radio", "button", "submit", "reset", "range", "color", "file", "image", "hidden"].includes(el.type)
    }
    const update = () => setOpen(Boolean(coarse?.matches) && typing(document.activeElement))
    // focusout fires before the next field takes focus; read on the next frame.
    const later = () => requestAnimationFrame(update)
    document.addEventListener("focusin", update)
    document.addEventListener("focusout", later)
    return () => {
      document.removeEventListener("focusin", update)
      document.removeEventListener("focusout", later)
    }
  }, [])
  return open
}

export function PhoneTabBar({
  start,
  end,
  createLabel,
  onCreate,
  navLabel,
  linkComponent,
  className,
}: PhoneTabBarProps) {
  const LinkComponent = (linkComponent ?? "a") as React.ElementType
  const keyboardOpen = useKeyboardOpen()

  const tab = (item: PhoneTab | null, index: number) => {
    if (item === null) return <span key={`empty-${index}`} aria-hidden="true" />
    const count = item.count && item.count > 0 ? (item.count > 99 ? "99+" : String(item.count)) : null
    return (
      <LinkComponent
        key={item.id}
        href={item.href}
        aria-current={item.active ? "page" : undefined}
        data-testid={`phone-tab-${item.id}`}
        className={cn(
          "relative flex min-h-12 flex-col items-center justify-end gap-0.5 rounded-lg pb-1.5 text-xs text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring",
          item.active && "font-semibold text-primary"
        )}
      >
        <Icon name={item.icon} size={24} />
        <span className="max-w-full truncate px-0.5">{item.label}</span>
        {count && (
          <b
            className="absolute top-0 start-[calc(50%+6px)] grid h-5 min-w-5 place-items-center rounded-full bg-destructive px-1.5 text-xs leading-none font-semibold text-destructive-foreground"
            aria-label={item.countLabel}
          >
            <span aria-hidden={item.countLabel ? true : undefined}>{count}</span>
          </b>
        )}
      </LinkComponent>
    )
  }

  return (
    <nav
      aria-label={navLabel}
      data-slot="phone-tab-bar"
      hidden={keyboardOpen || undefined}
      className={cn(
        "fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card pb-[env(safe-area-inset-bottom)] ps-[env(safe-area-inset-left)] pe-[env(safe-area-inset-right)] sm:hidden print:hidden",
        className
      )}
    >
      <div className="grid h-16 grid-cols-5 items-end px-2 pt-1.5">
        {start.map(tab)}
        <button
          type="button"
          onClick={onCreate}
          aria-label={createLabel}
          data-testid="phone-tab-create"
          className="mb-2 grid size-14 place-items-center justify-self-center rounded-2xl bg-primary text-primary-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          <Icon name="plus" size={28} />
        </button>
        {end.map(tab)}
      </div>
    </nav>
  )
}
