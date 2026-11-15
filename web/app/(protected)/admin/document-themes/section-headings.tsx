'use client';

import type * as React from 'react';
import { Icon } from '@whity/ui/icon';
import { cn } from '@whity/ui/utils';
import type { OptionValue } from './model';

/**
 * The six section heading styles (FP.4) as tiles with a tiny preview in the
 * print colour: A number and rule, B the Whity mark, C mono eyebrow, D
 * progress rail, E sheet tab, F sheet on its plane. A radio group: arrow
 * keys move the choice.
 *
 * The previews are printed-page miniatures, so their sizes are the sheet's,
 * not the screen's 13px floor; each tile's name is the readable label.
 */

/** The print colour and its tint as the variables the previews read. */
export function printVars(accent: string): React.CSSProperties {
  return { '--print': accent, '--print-tint': `color-mix(in srgb, ${accent} 14%, var(--card))` } as React.CSSProperties;
}

/** The previews, keyed by the stored value. */
const PREVIEWS: Record<string, () => React.ReactNode> = {
  number: () => <span className="font-semibold text-(--print) text-base">4</span>,
  mark: () => <MarkPreview n="4" />,
  eyebrow: () => <span className="font-mono text-(--print) text-[1em]">04 / 09</span>,
  rail: () => (
    <span className="flex gap-0.5" aria-hidden="true">
      {[0, 1, 2, 3, 4].map((i) => (
        <i key={i} className={cn('h-[3px] w-2 rounded-sm', i < 3 ? 'bg-(--print)' : 'bg-border')} />
      ))}
    </span>
  ),
  tab: () => (
    <span className="rounded-t-[5px] border-b-2 border-(--print) bg-(--print-tint) px-1.5 py-0.5 font-mono text-(--print) text-[0.9em]">
      SECTION 4
    </span>
  ),
  plane: () => <span className="rounded-md bg-(--print-tint) px-2.5 py-0.5 font-semibold text-(--print)">4</span>,
};

/** The Whity mark's two offset squares carrying the number (B). */
export function MarkPreview({ n, size = 22 }: { n: string; size?: number }) {
  const square = Math.round(size * 0.77);
  return (
    <span className="relative inline-block shrink-0" style={{ width: size, height: size }} aria-hidden="true">
      <i className="absolute start-0 top-0 rounded-[5px] bg-(--print)" style={{ width: square, height: square }} />
      <i
        className="absolute end-0 bottom-0 grid place-items-center rounded-[5px] border-2 border-(--print) bg-card font-semibold not-italic text-(--print) text-[0.9em]"
        style={{ width: square, height: square }}
      >
        {n}
      </i>
    </span>
  );
}

/** The letter a style's label starts with ("B · Whity mark" → "B"), for the A-F order. */
function letterOf(option: OptionValue): string {
  return option.label.trim().charAt(0);
}

export function SectionHeadingPicker({
  label,
  hint,
  options,
  value,
  locked,
  disabled,
  ar,
  accent,
  onChange,
}: {
  label: string;
  hint: string;
  options: OptionValue[];
  value: string;
  /** Free workspace: B only, the others shown with a lock. */
  locked: boolean;
  disabled: boolean;
  ar: boolean;
  /** The print colour the previews are drawn in. */
  accent: string;
  onChange: (value: string) => void;
}) {
  const ordered = [...options].sort((a, b) => letterOf(a).localeCompare(letterOf(b)));
  const usable = !disabled && !locked;
  const move = (from: number, step: number, el: HTMLElement) => {
    const next = ordered[(from + step + ordered.length) % ordered.length];
    onChange(next.value);
    const group = el.closest('[role=radiogroup]');
    (group?.querySelector(`[data-value="${next.value}"]`) as HTMLElement | null)?.focus();
  };

  return (
    <div className="grid gap-2">
      <span id="section-headings-label" className="text-sm font-medium text-foreground">
        {label}
      </span>
      <div
        role="radiogroup"
        aria-labelledby="section-headings-label"
        className="grid grid-cols-2 gap-2 sm:grid-cols-3"
        style={printVars(accent)}
      >
        {ordered.map((option, i) => {
          const selected = option.value === value;
          const Preview = PREVIEWS[option.value];
          return (
            <button
              key={option.value}
              type="button"
              role="radio"
              data-value={option.value}
              aria-checked={selected}
              aria-disabled={!usable || undefined}
              tabIndex={selected ? 0 : -1}
              onClick={() => usable && onChange(option.value)}
              onKeyDown={(e) => {
                if (!usable) return;
                const forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';
                const back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
                if (!forward && !back) return;
                e.preventDefault();
                const horizontal = e.key === 'ArrowRight' || e.key === 'ArrowLeft';
                move(i, (forward ? 1 : -1) * (ar && horizontal ? -1 : 1), e.currentTarget);
              }}
              className={cn(
                'grid min-h-16 content-start justify-items-start gap-1.5 rounded-control border bg-card p-2.5 text-start text-sm text-foreground outline-none',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                selected ? 'border-primary ring-1 ring-primary' : 'border-border',
                usable ? 'hover:bg-muted' : 'cursor-not-allowed',
                locked && !selected && 'opacity-50'
              )}
            >
              {/* A miniature of the printed heading, at the sheet's scale. */}
              <span className="flex h-6 items-center" aria-hidden="true" style={{ fontSize: 10 }}>
                {Preview ? <Preview /> : null}
              </span>
              <span className="inline-flex items-center gap-1">
                {ar ? option.labelAr : option.label}
                {locked && !selected && <Icon name="lock" size={12} />}
              </span>
            </button>
          );
        })}
      </div>
      <p className="text-xs text-muted-foreground">{hint}</p>
    </div>
  );
}
