import { avatarPatternShapes } from "./avatar-pattern"
import { Icon } from "./icon"
import { Mark } from "./mark"
import { cn } from "./utils"

/** The seeded pattern (M.34) as SVG elements: no markup is ever injected. */
function AvatarPattern({ seed, hue }: { seed: string; hue?: number }) {
  const { background, rotate, shapes } = avatarPatternShapes(seed, hue)
  return (
    <svg data-slot="avatar-pattern" viewBox="0 0 40 40" aria-hidden="true" className="block size-full rounded-full">
      <rect width="40" height="40" fill={background} />
      <g transform={`rotate(${rotate} 20 20)`}>
        {shapes.map((shape, index) =>
          shape.kind === "circle" ? (
            <circle key={index} cx={shape.cx} cy={shape.cy} r={shape.r} fill={shape.fill} />
          ) : (
            <rect
              key={index}
              x={shape.x}
              y={shape.y}
              width={shape.width}
              height={shape.height}
              rx={shape.rx}
              fill={shape.fill}
              {...(shape.dash ? { stroke: shape.stroke, strokeWidth: 2.6, strokeDasharray: "4.5 3" } : {})}
              {...(shape.rotate != null ? { transform: `rotate(${shape.rotate} 20 24)` } : {})}
            />
          )
        )}
      </g>
    </svg>
  )
}

export type ActorKind = "person" | "agent" | "system"

/** Up to two initials, from the first letters of the first two words. */
function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word.charAt(0))
      .join("")
      .toUpperCase() || "?"
  )
}

/**
 * Who did something, at a glance: people get their initials on grey (or a
 * photo, or a seeded Whity pattern, M.34), agents the robot face on the tint,
 * and the system the Whity mark.
 * Agents never get a human face or a photo (stage 8). An optional dot shows
 * presence. The reference is docs/design/components/Avatar (`.wy-avatar`).
 */
function Avatar({
  name,
  kind = "person",
  src,
  pattern,
  hue,
  size = 32,
  status,
  labels,
  className,
}: {
  name: string
  kind?: ActorKind
  /** A photo, for people only. */
  src?: string
  /**
   * A stable seed (the Whity ID, or the person id): people without a photo get
   * the seeded pattern instead of initials (M.34).
   */
  pattern?: string
  /** The hue of the installation colour where the account was made (`brandHue`); Whity Blue by default. */
  hue?: number
  size?: number
  status?: "online" | "busy" | "offline"
  /** Overrides for the accessible name suffixes, e.g. Arabic. */
  labels?: { agent?: string }
  className?: string
}) {
  const L = { agent: "agent", ...labels }
  return (
    <span
      data-slot="avatar"
      data-kind={kind}
      role="img"
      aria-label={kind === "agent" ? `${name} (${L.agent})` : name}
      style={{ width: size, height: size, fontSize: Math.max(11, Math.round(size * 0.36)) }}
      className={cn(
        "relative inline-grid shrink-0 place-items-center rounded-full leading-none font-semibold",
        kind === "agent" && "bg-primary-tint text-primary-tint-foreground",
        kind === "system" && "bg-card shadow-[inset_0_0_0_1px_var(--border)]",
        kind === "person" && "bg-muted text-foreground",
        className
      )}
    >
      {kind === "agent" ? (
        <Icon name="robot" size={Math.round(size * 0.56)} stroke={2} />
      ) : kind === "system" ? (
        <Mark size={Math.round(size * 0.62)} label="" />
      ) : src ? (
        <img src={src} alt="" className="size-full rounded-full object-cover" />
      ) : pattern != null ? (
        <AvatarPattern seed={pattern} hue={hue} />
      ) : (
        initials(name)
      )}
      {status && (
        <span
          aria-hidden="true"
          className={cn(
            "absolute -end-px -bottom-px box-border size-[32%] min-h-2 min-w-2 rounded-full border-2 border-card",
            status === "online" ? "bg-success" : status === "busy" ? "bg-warning" : "bg-muted-foreground"
          )}
        />
      )}
    </span>
  )
}

export { Avatar, initials }
