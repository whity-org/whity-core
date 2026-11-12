"use client"

import * as React from "react"

import { cn } from "./utils"

/**
 * The motion player (LT.1-LT.4, docs/design/06-motion.md, handoff/motion/README.md),
 * a React port of `handoff/motion/whity-lottie.js`.
 *
 * - The 31 files and lottie-web's light build are served from `/motion/` and
 *   fetched only when a scene is first shown, so a screen without motion
 *   downloads nothing.
 * - Every fill and stroke named `c:<token>` takes the live CSS token, so a scene
 *   follows dark mode and the workspace's colour. The player rebuilds, keeping
 *   its frame, when the theme changes.
 * - `<html data-motion="full|calm|off">` is the person's Animations setting.
 *   Calm and Off show the last frame (Calm fades it in); the device's reduce
 *   motion means Off. Loops hold still.
 * - A scene never carries the meaning alone and never holds a button: it is
 *   `aria-hidden`, and the words around it say what happened.
 */

export type MomentName =
  | "agents-propose"
  | "all-caught-up"
  | "approve-set-down"
  | "brand-sheet-platform"
  | "documents-sign"
  | "empty-documents"
  | "empty-invoices"
  | "empty-people"
  | "empty-stock"
  | "invoice-sent"
  | "money-invoice"
  | "no-results"
  | "not-found"
  | "offline"
  | "payment-received"
  | "security-lock"
  | "setup-done"
  | "spot-cliq"
  | "spot-code"
  | "spot-jod"
  | "spot-jofotara"
  | "spot-languages"
  | "spot-mcp"
  | "spot-plugin"
  | "spot-whatsapp"
  | "stock-batches"
  | "stock-received"
  | "updating"
  | "uploading"
  | "waiting-reply"
  | "went-wrong"

/** The Animations setting (LT.4). */
export type MotionMode = "full" | "calm" | "off"

export const MOTION_MODES: readonly MotionMode[] = ["full", "calm", "off"]

/** Where the files and the player library are served from. */
const MotionBaseContext = React.createContext("/motion")

/** Serve the motion files from somewhere other than `/motion` (a plugin, Storybook). */
export function MotionBaseProvider({ base, children }: { base: string; children: React.ReactNode }) {
  return <MotionBaseContext.Provider value={base.replace(/\/$/, "")}>{children}</MotionBaseContext.Provider>
}

interface LottieAnimation {
  totalFrames: number
  currentFrame: number
  loop: boolean | number
  play(): void
  destroy(): void
  goToAndStop(value: number, isFrame?: boolean): void
  goToAndPlay(value: number, isFrame?: boolean): void
  setDirection(direction: 1 | -1): void
  addEventListener(name: string, callback: () => void): void
}

interface LottieLibrary {
  loadAnimation(options: {
    container: Element
    renderer: "svg"
    loop: boolean
    autoplay: boolean
    animationData: unknown
    rendererSettings?: { preserveAspectRatio?: string }
  }): LottieAnimation
}

declare global {
  interface Window {
    lottie?: LottieLibrary
  }
}

// Fill and stroke names in the files, and the tokens they take.
const TOKENS: Record<string, string> = {
  primary: "--primary",
  plane: "--plane-deep",
  card: "--card",
  line: "--border",
  muted: "--muted",
  ink: "--foreground",
  soft: "--muted-foreground",
  ok: "--success",
  tint: "--primary-tint",
  tenant: "--tenant-color",
  onprimary: "--primary-foreground",
}

let libraryPromise: Promise<LottieLibrary | null> | null = null

function loadLibrary(base: string): Promise<LottieLibrary | null> {
  if (typeof window === "undefined") return Promise.resolve(null)
  if (window.lottie) return Promise.resolve(window.lottie)
  if (!libraryPromise) {
    libraryPromise = new Promise((resolve) => {
      const script = document.createElement("script")
      script.src = `${base}/lottie_light.min.js`
      script.async = true
      script.onload = () => resolve(window.lottie ?? null)
      script.onerror = () => {
        libraryPromise = null
        resolve(null)
      }
      document.head.appendChild(script)
    })
  }
  return libraryPromise
}

const dataCache = new Map<string, Promise<unknown>>()

function loadData(src: string): Promise<unknown> {
  if (typeof fetch === "undefined") return Promise.reject(new Error("no fetch"))
  let pending = dataCache.get(src)
  if (!pending) {
    pending = fetch(src).then((response) => {
      if (!response.ok) throw new Error(String(response.status))
      return response.json()
    })
    pending.catch(() => dataCache.delete(src))
    dataCache.set(src, pending)
  }
  return pending
}

let canvasContext: CanvasRenderingContext2D | null = null

/** Any CSS colour (oklch included) as Lottie's 0-1 RGBA, through a 1px canvas. */
function toLottieColour(value: string): number[] | null {
  try {
    if (!canvasContext) {
      const canvas = document.createElement("canvas")
      canvas.width = canvas.height = 1
      canvasContext = canvas.getContext("2d", { willReadFrequently: true })
    }
    if (!canvasContext) return null
    canvasContext.clearRect(0, 0, 1, 1)
    canvasContext.fillStyle = "#000"
    canvasContext.fillStyle = value.trim()
    canvasContext.fillRect(0, 0, 1, 1)
    const [r, g, b] = canvasContext.getImageData(0, 0, 1, 1).data
    return [r / 255, g / 255, b / 255, 1]
  } catch {
    return null
  }
}

function recolour(raw: unknown, element: Element): unknown {
  const data = JSON.parse(JSON.stringify(raw)) as unknown
  const style = getComputedStyle(element)
  const colours: Record<string, number[] | null> = {}
  for (const [name, token] of Object.entries(TOKENS)) {
    const value = style.getPropertyValue(token)
    if (value && value.trim()) colours[name] = toLottieColour(value)
  }
  const walk = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(walk)
      return
    }
    if (!node || typeof node !== "object") return
    const shape = node as { ty?: string; nm?: unknown; c?: { a?: number; k?: unknown } }
    if ((shape.ty === "fl" || shape.ty === "st") && typeof shape.nm === "string" && shape.nm.startsWith("c:")) {
      const colour = colours[shape.nm.slice(2)]
      if (colour && shape.c && shape.c.a === 0) shape.c.k = colour
    }
    for (const value of Object.values(node)) if (value && typeof value === "object") walk(value)
  }
  walk(data)
  return data
}

function readMotionMode(): MotionMode {
  if (typeof window === "undefined") return "full"
  if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return "off"
  const mode = document.documentElement.dataset.motion
  return mode === "calm" || mode === "off" ? mode : "full"
}

function subscribeToMotion(callback: () => void): () => void {
  const observer = new MutationObserver(callback)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-motion"] })
  const query = window.matchMedia?.("(prefers-reduced-motion: reduce)")
  query?.addEventListener?.("change", callback)
  return () => {
    observer.disconnect()
    query?.removeEventListener?.("change", callback)
  }
}

/** The motion the person gets: their Animations setting, or Off when the device asks for less motion. */
export function useMotionMode(): MotionMode {
  return React.useSyncExternalStore(subscribeToMotion, readMotionMode, () => "full")
}

// Theme changes (light, dark, a workspace colour) rebuild the scene with the new colours.
function readTheme(): string {
  const root = document.documentElement
  return `${root.className}|${root.dataset.theme ?? ""}|${root.getAttribute("style") ?? ""}`
}

function subscribeToTheme(callback: () => void): () => void {
  const observer = new MutationObserver(callback)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class", "data-theme", "style"] })
  const query = window.matchMedia?.("(prefers-color-scheme: dark)")
  query?.addEventListener?.("change", callback)
  return () => {
    observer.disconnect()
    query?.removeEventListener?.("change", callback)
  }
}

function seenBefore(key: string | undefined, ever: string | undefined): boolean {
  try {
    if (ever && localStorage.getItem(`whity.onceEver.${ever}`) === "1") return true
    return !!key && sessionStorage.getItem(`whity.once.${key}`) === "1"
  } catch {
    return false
  }
}

function markSeen(key: string | undefined, ever: string | undefined): void {
  try {
    if (ever) localStorage.setItem(`whity.onceEver.${ever}`, "1")
    if (key) sessionStorage.setItem(`whity.once.${key}`, "1")
  } catch {
    // A private window can refuse storage; the scene then plays again next time.
  }
}

/** Whether a first-run scene (`onceEver`) has already played on this device. */
export function hasPlayedOnceEver(key: string): boolean {
  return seenBefore(undefined, key)
}

/** Record a first-run scene as played without showing it. */
export function markPlayedOnceEver(key: string): void {
  markSeen(undefined, key)
}

export interface LottieProps {
  name: MomentName
  /** A file elsewhere; defaults to `<base>/<name>.json`. */
  src?: string
  /**
   * `view` plays once when seen (a click replays it); `manual` follows
   * `approved` forwards and backwards; `loop` plays only while `active`.
   */
  play?: "view" | "manual" | "loop"
  /** Play once a session under this key, then show the last frame (empty states). */
  once?: string
  /** Play once ever on this device under this key (first-run scenes), then show the last frame. */
  onceEver?: string
  /** `manual` only: the approved end (forwards) or the waiting start (backwards). */
  approved?: boolean
  /** `loop` only: false when the waiting is over; the scene stops on its last frame. */
  active?: boolean
  /** Milliseconds to wait after the scene is seen. */
  delay?: number
  /** Shown until the scene is ready, and kept if the player can't load. */
  fallback?: React.ReactNode
  className?: string
}

function Lottie({
  name,
  src,
  play = "view",
  once,
  onceEver,
  approved = false,
  active = true,
  delay = 0,
  fallback,
  className,
}: LottieProps) {
  const base = React.useContext(MotionBaseContext)
  const file = src ?? `${base}/${name}.json`
  const mode = useMotionMode()
  const theme = React.useSyncExternalStore(subscribeToTheme, readTheme, () => "")
  const hostRef = React.useRef<HTMLDivElement>(null)
  const stageRef = React.useRef<HTMLDivElement>(null)
  const animRef = React.useRef<LottieAnimation | null>(null)
  const playedRef = React.useRef(false)
  const [ready, setReady] = React.useState(false)

  // Read inside the build without rebuilding when they change.
  const live = React.useRef({ approved, active, mode, delay, once, onceEver })
  live.current = { approved, active, mode, delay, once, onceEver }

  React.useEffect(() => {
    const host = hostRef.current
    const stage = stageRef.current
    if (!host || !stage) return
    let cancelled = false
    let observer: IntersectionObserver | null = null
    let timer: ReturnType<typeof setTimeout> | undefined
    const keepFrame = animRef.current ? Math.round(animRef.current.currentFrame) : null

    // The library first: without it (no script, a test runner) nothing is fetched.
    void loadLibrary(base)
      .then((library) => (library && !cancelled ? Promise.all([library, loadData(file)] as const) : null))
      .then((loaded) => {
        if (cancelled || !loaded) return
        const [library, raw] = loaded
        animRef.current?.destroy()
        stage.textContent = ""
        const anim = library.loadAnimation({
          container: stage,
          renderer: "svg",
          loop: play === "loop",
          autoplay: false,
          animationData: recolour(raw, host),
          rendererSettings: { preserveAspectRatio: "xMidYMid meet" },
        })
        animRef.current = anim
        anim.addEventListener("DOMLoaded", () => {
          if (cancelled) return
          setReady(true)
          const last = anim.totalFrames - 1
          const { approved: isApproved, active: isActive, mode: motion, delay: wait, once: key, onceEver: ever } = live.current
          if (play === "manual") {
            anim.goToAndStop(keepFrame != null && keepFrame > 0 ? keepFrame : isApproved ? last : 0, true)
          } else if (play === "loop") {
            if (!isActive) anim.goToAndStop(last, true)
            else if (motion !== "full") anim.goToAndStop(0, true)
            else anim.play()
          } else if (playedRef.current || motion !== "full" || seenBefore(key, ever)) {
            anim.goToAndStop(keepFrame ?? last, true)
            if (motion === "calm" && keepFrame == null) {
              stage.animate?.([{ opacity: 0 }, { opacity: 1 }], { duration: 400, easing: "ease-out" })
            }
          } else {
            anim.goToAndStop(0, true)
            if (typeof IntersectionObserver === "undefined") {
              anim.goToAndStop(last, true)
              return
            }
            observer = new IntersectionObserver(
              (entries) => {
                if (!entries.some((entry) => entry.isIntersecting && entry.intersectionRatio > 0.35)) return
                observer?.disconnect()
                playedRef.current = true
                markSeen(key, ever)
                timer = setTimeout(() => animRef.current?.goToAndPlay(0, true), wait)
              },
              { threshold: [0, 0.35, 0.6] }
            )
            observer.observe(host)
          }
        })
      })
      .catch(() => {
        // The fallback stays; the words around the scene already say everything.
      })

    return () => {
      cancelled = true
      observer?.disconnect()
      if (timer) clearTimeout(timer)
    }
  }, [base, file, play, theme, mode])

  React.useEffect(() => () => animRef.current?.destroy(), [])

  // Approve plays forwards, Undo backwards; less motion jumps between the two.
  const firstApproved = React.useRef(true)
  React.useEffect(() => {
    if (firstApproved.current) {
      firstApproved.current = false
      return
    }
    const anim = animRef.current
    if (play !== "manual" || !anim) return
    if (live.current.mode !== "full") anim.goToAndStop(approved ? anim.totalFrames - 1 : 0, true)
    else {
      anim.setDirection(approved ? 1 : -1)
      anim.play()
    }
  }, [approved, play])

  // The waiting is over: stop the loop and show its end.
  React.useEffect(() => {
    const anim = animRef.current
    if (play !== "loop" || !anim) return
    if (!active) {
      anim.loop = false
      anim.goToAndStop(anim.totalFrames - 1, true)
    } else if (live.current.mode === "full") {
      anim.loop = true
      anim.play()
    }
  }, [active, play])

  const replay = () => {
    if (play !== "view" || mode !== "full") return
    animRef.current?.goToAndPlay(0, true)
  }

  return (
    <div
      ref={hostRef}
      data-slot="lottie"
      data-moment={name}
      data-ready={ready ? "" : undefined}
      aria-hidden="true"
      onClick={replay}
      className={cn("relative", className)}
    >
      {!ready && fallback ? <div className="absolute inset-0 grid place-items-center">{fallback}</div> : null}
      <div ref={stageRef} className="size-full" />
    </div>
  )
}

export { Lottie }
