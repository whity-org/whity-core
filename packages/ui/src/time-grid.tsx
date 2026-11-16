"use client"

import * as React from "react"

import { cn } from "./utils"

/**
 * A day's time grid (docs/design/components/TplAppointments; `.wk-cal`,
 * `.wk-appt`, `.wk-now` in bundle.css), for Appointments and the calendar.
 *
 * Columns are people and rooms; each block sits at its time. Booked is blue,
 * checked in green, a request dashed amber ("needs your OK": not happened yet),
 * blocked time grey. A red line marks now. Times are "HH:MM" in the day's own
 * clock and always show in Western digits, left to right.
 */

export type TimeBlockState = "booked" | "checked-in" | "request" | "blocked"

export interface TimeGridColumn {
  id: string
  /** The column head: a person (Avatar and name) or a room. */
  header: React.ReactNode
}

export interface TimeBlock {
  id: string
  column: string
  /** "HH:MM". */
  start: string
  end: string
  title: React.ReactNode
  detail?: React.ReactNode
  state?: TimeBlockState
  onOpen?: () => void
}

export interface TimeGridProps {
  columns: TimeGridColumn[]
  blocks: TimeBlock[]
  /** First and last whole hours shown. */
  startHour?: number
  endHour?: number
  /** Pixels per hour. */
  hourHeight?: number
  /** "HH:MM", when today is shown. */
  now?: string
  /** The grid's accessible name ("Sunday 8 November, Abdoun branch"). */
  label: string
  className?: string
}

function toMinutes(time: string): number {
  const [h, m] = time.split(":").map((part) => parseInt(part, 10))
  return (Number.isFinite(h) ? h : 0) * 60 + (Number.isFinite(m) ? m : 0)
}

const STATE_CLASSES: Record<TimeBlockState, string> = {
  booked: "bg-primary-tint text-primary-tint-foreground border-s-[3px] border-primary",
  "checked-in": "bg-success-tint text-success-text border-s-[3px] border-success",
  request: "bg-transparent text-warning-text border-[1.5px] border-dashed border-warning",
  blocked: "bg-muted text-muted-foreground border-s-[3px] border-input",
}

function TimeGrid({
  columns,
  blocks,
  startHour = 8,
  endHour = 18,
  hourHeight = 64,
  now,
  label,
  className,
}: TimeGridProps) {
  const hours = Array.from({ length: Math.max(0, endHour - startHour) }, (_, index) => startHour + index)
  const top = (time: string) => ((toMinutes(time) - startHour * 60) / 60) * hourHeight
  const height = hours.length * hourHeight
  const nowTop = now != null ? top(now) : null

  return (
    <div
      data-slot="time-grid"
      role="group"
      aria-label={label}
      className={cn("overflow-x-auto rounded-card border border-border bg-card", className)}
    >
      <div
        className="grid min-w-fit"
        style={{ gridTemplateColumns: `64px repeat(${columns.length}, minmax(160px, 1fr))` }}
      >
        <div className="border-b border-border" />
        {columns.map((column) => (
          <div
            key={column.id}
            className="flex items-center gap-2 border-s border-b border-border px-3 py-2.5 text-sm font-semibold"
          >
            {column.header}
          </div>
        ))}
        <div aria-hidden="true">
          {hours.map((hour) => (
            <div
              key={hour}
              dir="ltr"
              className="box-border border-t border-border px-2 py-1 font-mono text-xs text-muted-foreground"
              style={{ height: hourHeight }}
            >
              {String(hour).padStart(2, "0")}:00
            </div>
          ))}
        </div>
        {columns.map((column) => (
          <div
            key={column.id}
            role="list"
            className="relative border-s border-border"
            style={{
              height,
              backgroundImage: `repeating-linear-gradient(to bottom, transparent 0 ${hourHeight - 1}px, var(--border) ${hourHeight - 1}px ${hourHeight}px)`,
            }}
          >
            {blocks
              .filter((block) => block.column === column.id)
              .map((block) => {
                const state = block.state ?? "booked"
                const blockTop = top(block.start)
                const blockHeight = Math.max(20, top(block.end) - blockTop - 4)
                const Element = block.onOpen ? "button" : "div"
                return (
                  <Element
                    key={block.id}
                    role="listitem"
                    data-state={state}
                    {...(block.onOpen ? { type: "button" as const, onClick: block.onOpen } : {})}
                    className={cn(
                      "absolute inset-x-1.5 overflow-hidden rounded-[8px] px-2 py-1.5 text-start text-xs leading-snug outline-none focus-visible:ring-2 focus-visible:ring-ring/30",
                      STATE_CLASSES[state]
                    )}
                    style={{ top: blockTop + 2, height: blockHeight }}
                  >
                    <span className="block font-semibold text-foreground">{block.title}</span>
                    <span dir="ltr" className="sr-only">
                      {` ${block.start}–${block.end} `}
                    </span>
                    {block.detail}
                  </Element>
                )
              })}
            {nowTop != null && nowTop >= 0 && nowTop <= height && (
              <div
                aria-hidden="true"
                data-slot="time-grid-now"
                className="absolute inset-x-0 z-[2] h-0.5 bg-destructive before:absolute before:-start-1 before:-top-1 before:size-2.5 before:rounded-full before:bg-destructive"
                style={{ top: nowTop }}
              />
            )}
          </div>
        ))}
      </div>
    </div>
  )
}

export { TimeGrid }
