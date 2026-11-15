'use client';

import type * as React from 'react';
import type { Block, PerDocumentPart } from '@whity/ui/editor/contract';
import { partText, segments } from '@whity/ui/editor/saved-parts';

/** The blocks whose `content` is a line of text. */
const TEXT_TYPES = new Set(['paragraph', 'heading', 'quote', 'bullet', 'number', 'checklist', 'callout']);

/**
 * A saved block's text, as a quiet preview in the side panel (TplSavedBlocks).
 * Per-document parts (E.23, E.23a) are marked in the main colour and named by
 * their label, found by stable key, never by position. Blocks that aren't a
 * line of text (tables, images) show as an ellipsis.
 */
export function SavedBlockPreview({
  blocks,
  parts,
  arabic,
  partLabel,
}: {
  blocks: Block[];
  parts: PerDocumentPart[];
  arabic: boolean;
  /** "Filled in each document: {label}", for the part's tooltip and screen readers. */
  partLabel: (label: string, required: boolean) => string;
}) {
  const byKey = new Map(parts.map((part) => [part.key, part]));
  const lines: React.ReactNode[] = [];
  let skipped = false;
  for (const block of blocks) {
    const content = (block as { content?: unknown }).content;
    if (!TEXT_TYPES.has(block.type) || !Array.isArray(content)) {
      // A run of blocks that aren't text shows as one ellipsis.
      if (!skipped) lines.push(<p key={block.id} className="text-muted-foreground">…</p>);
      skipped = true;
      continue;
    }
    skipped = false;
    lines.push(
      <p key={block.id} dir="auto">
        {segments(block.id, content).map((segment, i) => {
          const text = partText(segment.runs);
          if (segment.part === null) return <span key={i}>{text}</span>;
          const part = byKey.get(segment.part) ?? byKey.get(segment.legacy);
          const label = part ? (arabic && part.label.ar) || part.label.en : text;
          return (
            <span key={i} className="text-primary" title={partLabel(label, part?.required === true)} data-part={segment.part}>
              {text || label}
            </span>
          );
        })}
      </p>
    );
  }
  return (
    <div className="grid gap-0.5 rounded-control border border-dashed border-input bg-muted p-3 text-sm">{lines}</div>
  );
}
