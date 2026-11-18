import * as React from "react"

import type { MotionMode } from "./lottie"

/**
 * A person's display preferences (W.3, A.1, A.3, LT.4): text size, Show technical
 * details and Animations. The app's provider stores them on the account; this is the half
 * components read. `document.documentElement.dataset.textSize` carries the size,
 * and the token CSS scales from it, so no component needs to know.
 */
export type TextSize = "standard" | "larger"

/** The localStorage key, so the first paint can apply the size before React runs. */
export const TEXT_SIZE_STORAGE_KEY = "whity-text-size"

/** The localStorage key for Animations, applied as `<html data-motion>` before React runs. */
export const MOTION_STORAGE_KEY = "whity-motion"

/** Higher contrast (TH.12): text at 7:1 whatever the workspace's theme, as `<html data-contrast="high">`. */
export type Contrast = "standard" | "high"

/** The localStorage key for Higher contrast, applied before React runs. */
export const CONTRAST_STORAGE_KEY = "whity-contrast"

export interface DisplayPreferences {
  textSize: TextSize
  showTechnicalDetails: boolean
  /** Full (default), Calm or Off (LT.4). The device's reduce motion means Off on top. */
  animations: MotionMode
  /** Standard (default) or high (TH.12). */
  contrast?: Contrast
}

export interface DisplayPreferencesValue extends DisplayPreferences {
  update: (patch: Partial<DisplayPreferences>) => void
}

const DisplayPreferencesContext = React.createContext<DisplayPreferencesValue>({
  textSize: "standard",
  showTechnicalDetails: false,
  animations: "full",
  contrast: "standard",
  update: () => {},
})

export const DisplayPreferencesContextProvider = DisplayPreferencesContext.Provider

export function useDisplayPreferences(): DisplayPreferencesValue {
  return React.useContext(DisplayPreferencesContext)
}

/**
 * Codes, ids and stack traces (A.3): renders its children only when the person
 * turned on Show technical details, or `force` where a page offers its own toggle.
 */
export function TechnicalDetails({ children, force = false }: { children: React.ReactNode; force?: boolean }) {
  const { showTechnicalDetails } = useDisplayPreferences()
  return showTechnicalDetails || force ? <>{children}</> : null
}
