import * as React from "react"

/**
 * A person's display preferences (W.3, A.1, A.3): text size and Show technical
 * details. The app's provider stores them on the account; this is the half
 * components read. `document.documentElement.dataset.textSize` carries the size,
 * and the token CSS scales from it, so no component needs to know.
 */
export type TextSize = "standard" | "larger"

/** The localStorage key, so the first paint can apply the size before React runs. */
export const TEXT_SIZE_STORAGE_KEY = "whity-text-size"

export interface DisplayPreferences {
  textSize: TextSize
  showTechnicalDetails: boolean
}

export interface DisplayPreferencesValue extends DisplayPreferences {
  update: (patch: Partial<DisplayPreferences>) => void
}

const DisplayPreferencesContext = React.createContext<DisplayPreferencesValue>({
  textSize: "standard",
  showTechnicalDetails: false,
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
