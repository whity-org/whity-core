'use client';

/**
 * A drawn signature (WHIT-922): drawing one, and showing one.
 *
 * Kept as strokes — lists of [x, y] points inside a box — rather than as an
 * image, the same shape the server stores, hashes and seals. Both halves draw
 * with SVG in `currentColor`, so the ink follows the theme's foreground token in
 * light and dark.
 *
 * Drawing needs a pointer (mouse, pen or finger). The owner chose a REQUIRED
 * drawn signature (decision C1), so a person who can only use a keyboard cannot
 * sign yet; the pad says so rather than leaving them stuck.
 */

import { useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';

export type SignaturePoint = [number, number];

export interface SignatureAppearance {
  width: number;
  height: number;
  strokes: SignaturePoint[][];
}

export const PAD_WIDTH = 480;
export const PAD_HEIGHT = 160;

function pathOf(stroke: SignaturePoint[]): string {
  return stroke.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x} ${y}`).join(' ');
}

/** Points drawn, across every stroke. The server needs at least five. */
export function pointCount(appearance: SignatureAppearance | null): number {
  return appearance === null ? 0 : appearance.strokes.reduce((n, s) => n + s.length, 0);
}

/** A signature, drawn read-only. */
export function SignatureDrawing({
  appearance,
  label,
  className,
}: {
  appearance: SignatureAppearance;
  label: string;
  className?: string;
}) {
  return (
    <svg
      viewBox={`0 0 ${appearance.width} ${appearance.height}`}
      role="img"
      aria-label={label}
      className={`h-auto w-full max-w-sm text-foreground ${className ?? ''}`}
      data-slot="signature-drawing"
    >
      {appearance.strokes.map((stroke, i) =>
        stroke.length === 1 ? (
          <circle key={i} cx={stroke[0][0]} cy={stroke[0][1]} r={1.5} fill="currentColor" />
        ) : (
          <path
            key={i}
            d={pathOf(stroke)}
            fill="none"
            stroke="currentColor"
            strokeWidth={2.5}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        )
      )}
    </svg>
  );
}

/** The pad a signer draws on. */
export function SignaturePad({
  value,
  onChange,
  disabled,
}: {
  value: SignatureAppearance | null;
  onChange: (next: SignatureAppearance | null) => void;
  disabled?: boolean;
}) {
  const t = useTranslation('documents');
  const svgRef = useRef<SVGSVGElement | null>(null);
  const [drawing, setDrawing] = useState(false);

  const strokes = value?.strokes ?? [];

  const pointFrom = (event: React.PointerEvent<SVGSVGElement>): SignaturePoint | null => {
    const svg = svgRef.current;
    if (svg === null) return null;
    const rect = svg.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return null;
    const x = ((event.clientX - rect.left) / rect.width) * PAD_WIDTH;
    const y = ((event.clientY - rect.top) / rect.height) * PAD_HEIGHT;
    const clamp = (v: number, max: number) => Math.round(Math.min(max, Math.max(0, v)) * 10) / 10;
    return [clamp(x, PAD_WIDTH), clamp(y, PAD_HEIGHT)];
  };

  const start = (event: React.PointerEvent<SVGSVGElement>) => {
    if (disabled) return;
    const point = pointFrom(event);
    if (point === null) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    setDrawing(true);
    onChange({ width: PAD_WIDTH, height: PAD_HEIGHT, strokes: [...strokes, [point]] });
  };

  const move = (event: React.PointerEvent<SVGSVGElement>) => {
    if (!drawing || disabled) return;
    const point = pointFrom(event);
    if (point === null || strokes.length === 0) return;
    const last = strokes[strokes.length - 1];
    const prev = last[last.length - 1];
    if (prev !== undefined && Math.abs(prev[0] - point[0]) < 1 && Math.abs(prev[1] - point[1]) < 1) return;
    onChange({
      width: PAD_WIDTH,
      height: PAD_HEIGHT,
      strokes: [...strokes.slice(0, -1), [...last, point]],
    });
  };

  const end = () => setDrawing(false);

  return (
    <div className="space-y-2" data-slot="signature-pad">
      <svg
        ref={svgRef}
        viewBox={`0 0 ${PAD_WIDTH} ${PAD_HEIGHT}`}
        role="img"
        aria-label={t('signing.pad.label', 'Signature pad. Draw your signature with a mouse, pen or finger.')}
        className="block h-auto w-full max-w-xl touch-none rounded-md border border-border bg-background text-foreground"
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={end}
        onPointerCancel={end}
        onPointerLeave={end}
        data-testid="signature-pad"
      >
        <line
          x1={24}
          x2={PAD_WIDTH - 24}
          y1={PAD_HEIGHT - 32}
          y2={PAD_HEIGHT - 32}
          className="text-muted-foreground"
          stroke="currentColor"
          strokeDasharray="4 4"
        />
        {strokes.map((stroke, i) =>
          stroke.length === 1 ? (
            <circle key={i} cx={stroke[0][0]} cy={stroke[0][1]} r={1.5} fill="currentColor" />
          ) : (
            <path
              key={i}
              d={pathOf(stroke)}
              fill="none"
              stroke="currentColor"
              strokeWidth={2.5}
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          )
        )}
      </svg>
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onChange(null)}
          disabled={disabled || strokes.length === 0}
        >
          {t('signing.pad.clear', 'Clear')}
        </Button>
        <p className="text-xs text-muted-foreground">
          {t(
            'signing.pad.pointerOnly',
            'Drawing needs a mouse, pen or touch screen. If you cannot draw, ask the person who sent this to you.'
          )}
        </p>
      </div>
    </div>
  );
}
