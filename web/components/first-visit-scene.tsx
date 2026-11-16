'use client';

import { useEffect, useRef, useState } from 'react';
import { Lottie, hasPlayedOnceEver, markPlayedOnceEver, type MomentName } from '@whity/ui/lottie';
import { cn } from '@whity/ui/utils';

/**
 * A first-run scene (LT.4: `brand-sheet-platform`, `agents-propose`,
 * `setup-done`): it plays on the person's first visit to a place and is gone on
 * every visit after, so a screen people return to every day carries no
 * picture. Remembered on this device; the Animations setting and reduce motion
 * apply as everywhere (Calm and Off show the last frame).
 *
 * Decided after mounting, so the server render and the first client render
 * agree and nothing flashes for someone who has already seen it.
 */
export function FirstVisitScene({
  name,
  storageKey,
  className,
}: {
  name: MomentName;
  /** Where "already seen" is kept; one key per place. */
  storageKey: string;
  className?: string;
}) {
  const [show, setShow] = useState(false);
  const decided = useRef<boolean | null>(null);

  useEffect(() => {
    // Decided once per mount (a development double effect must not read back
    // its own mark), and marked now rather than when it plays: with Calm or Off
    // it never "plays", and it still belongs to the first visit only.
    if (decided.current === null) {
      decided.current = !hasPlayedOnceEver(storageKey);
      if (decided.current) markPlayedOnceEver(storageKey);
    }
    setShow(decided.current);
  }, [storageKey]);

  if (!show) return null;
  return <Lottie name={name} className={cn('mx-auto h-[135px] w-[200px]', className)} />;
}
