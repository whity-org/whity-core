import type { ReactNode } from 'react';
import { Icon, type IconName } from '@whity/ui/icon';
import { cn } from '@whity/ui/utils';

/**
 * The round icon above a public page's one answer (WHIT-1044; the cards'
 * `x4-authc__ic`): `brand` on the workspace's tint, `ok` green, `warn` amber,
 * `muted` grey (verify's "not found" and "withdrawn", never red), and `dash`
 * for "not yet" (waiting, checking): dashed, no fill.
 */
export type OutcomeTone = 'brand' | 'ok' | 'warn' | 'muted' | 'dash';

const TONES: Record<OutcomeTone, string> = {
  brand: 'bg-primary-tint text-primary-tint-foreground',
  ok: 'bg-success-tint text-success-text',
  warn: 'bg-warning-tint text-warning-text',
  muted: 'bg-muted text-muted-foreground',
  dash: 'border-2 border-dashed border-input text-muted-foreground',
};

export function OutcomeIcon({
  name,
  tone = 'brand',
  className,
}: {
  name: IconName;
  tone?: OutcomeTone;
  className?: string;
}) {
  return (
    <span
      aria-hidden="true"
      data-tone={tone}
      className={cn('grid size-14 shrink-0 place-items-center rounded-full', TONES[tone], className)}
    >
      <Icon name={name} size={24} />
    </span>
  );
}

/**
 * One answer on a public page (TplJoinWorkspace, TplWelcomeWorkspace,
 * TplVerifyDocument, TplPaymentReturn): one icon, one title, what it means in
 * plain words, then at most one next step.
 */
export function PublicOutcome({
  icon,
  tone = 'brand',
  title,
  description,
  children,
  testId,
  className,
}: {
  icon: IconName;
  tone?: OutcomeTone;
  title: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  testId?: string;
  className?: string;
}) {
  return (
    <section
      data-testid={testId}
      className={cn('grid gap-4 rounded-card border border-border bg-card p-6 text-card-foreground', className)}
    >
      <OutcomeIcon name={icon} tone={tone} />
      <h1 className="text-xl font-semibold text-foreground" dir="auto">
        {title}
      </h1>
      {description ? (
        <p className="text-muted-foreground" dir="auto">
          {description}
        </p>
      ) : null}
      {children}
    </section>
  );
}
