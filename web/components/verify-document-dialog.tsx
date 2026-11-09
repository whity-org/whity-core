'use client';

/**
 * "Verify a document" before signing in (#1734): scan a printed document's QR
 * code with the camera, or type its link or code, and land on the public check
 * at /verify/{token}. No account needed, the same as pointing a phone's own
 * camera app at the paper.
 *
 * - The camera starts only after the reader has been told why and pressed
 *   "Use the camera", so the browser's permission prompt never comes cold.
 *   Frames are read on this device; nothing is uploaded.
 * - The camera stops the moment a code is accepted, the dialog closes, the
 *   reader switches to typing, or the component unmounts.
 * - Detection uses the browser's own `BarcodeDetector` where it reads QR codes
 *   and falls back to jsQR (Apache-2.0, no dependencies), loaded only then.
 * - Only this instance's own verification links are followed (parseVerifyInput):
 *   a QR code is untrusted input, and a scanner that opened any URL would be a
 *   phishing tool with our name on it.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { IconCamera, IconKeyboard } from '@tabler/icons-react';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@whity/features/i18n';
import { parseVerifyInput, type VerifyInput } from '@/lib/verify-scan';

type Phase = 'intro' | 'starting' | 'scanning' | 'denied' | 'unavailable' | 'manual';

/** Reads one video frame; null when there is no code in it. */
type Detect = (video: HTMLVideoElement) => Promise<string | null>;

type BarcodeDetectorLike = {
  detect(source: CanvasImageSource): Promise<Array<{ rawValue: string }>>;
};
type BarcodeDetectorCtor = {
  new (options: { formats: string[] }): BarcodeDetectorLike;
  getSupportedFormats?: () => Promise<string[]>;
};

/** The longest side a frame is scaled to before jsQR reads it. */
const FALLBACK_MAX_SIDE = 720;
/** Pause between reads, so a slow phone keeps a live preview. */
const SCAN_INTERVAL_MS = 160;

/** The browser's detector where it reads QR codes, else jsQR on a canvas. */
export async function createDetector(): Promise<{ detect: Detect; engine: 'native' | 'fallback' }> {
  const Native = (globalThis as { BarcodeDetector?: BarcodeDetectorCtor }).BarcodeDetector;
  if (Native) {
    try {
      const formats = Native.getSupportedFormats ? await Native.getSupportedFormats() : ['qr_code'];
      if (formats.includes('qr_code')) {
        const detector = new Native({ formats: ['qr_code'] });
        return {
          engine: 'native',
          detect: async (video) => {
            const found = await detector.detect(video);
            return found.length > 0 ? found[0].rawValue : null;
          },
        };
      }
    } catch {
      // A detector that exists but cannot start falls through to jsQR.
    }
  }

  const { default: jsQR } = await import('jsqr');
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  return {
    engine: 'fallback',
    detect: async (video) => {
      const { videoWidth: w, videoHeight: h } = video;
      if (!context || w === 0 || h === 0) return null;
      const scale = Math.min(1, FALLBACK_MAX_SIDE / Math.max(w, h));
      const cw = Math.round(w * scale);
      const ch = Math.round(h * scale);
      canvas.width = cw;
      canvas.height = ch;
      context.drawImage(video, 0, 0, cw, ch);
      const frame = context.getImageData(0, 0, cw, ch);
      return jsQR(frame.data, cw, ch, { inversionAttempts: 'attemptBoth' })?.data ?? null;
    },
  };
}

export function VerifyDocumentDialog({
  open,
  onOpenChange,
  siteName,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  siteName: string;
}) {
  const t = useTranslation('auth');
  const router = useRouter();
  const [phase, setPhase] = useState<Phase>('intro');
  const [refusal, setRefusal] = useState<Exclude<VerifyInput, { ok: true }>['reason'] | null>(null);
  const [typed, setTyped] = useState('');
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Bumped on every stop: a read still in flight from an older run must not
  // navigate or restart the loop.
  const runRef = useRef(0);
  const lastRefusedRef = useRef<string | null>(null);

  const stopCamera = useCallback(() => {
    runRef.current += 1;
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  // Leaving the page always releases the camera.
  useEffect(() => stopCamera, [stopCamera]);

  // Every way the dialog closes (the corner button, Escape, the overlay, a
  // code accepted) comes through here, so the camera stops with it and the
  // next open starts from the explanation again.
  const handleOpenChange = useCallback(
    (next: boolean) => {
      if (!next) {
        stopCamera();
        setPhase('intro');
        setRefusal(null);
        setTyped('');
        lastRefusedRef.current = null;
      }
      onOpenChange(next);
    },
    [onOpenChange, stopCamera]
  );

  const go = useCallback(
    (result: Extract<VerifyInput, { ok: true }>) => {
      handleOpenChange(false);
      router.push(result.path);
    },
    [handleOpenChange, router]
  );

  const startCamera = useCallback(async () => {
    setRefusal(null);
    if (typeof window === 'undefined' || !window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      setPhase('unavailable');
      return;
    }
    stopCamera();
    const run = runRef.current;
    setPhase('starting');
    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false,
      });
    } catch (error) {
      const name = error instanceof DOMException ? error.name : '';
      setPhase(name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : 'unavailable');
      return;
    }
    if (run !== runRef.current) {
      // Closed while the browser was asking.
      stream.getTracks().forEach((track) => track.stop());
      return;
    }
    streamRef.current = stream;
    setPhase('scanning');

    const video = videoRef.current;
    if (!video) return;
    video.srcObject = stream;
    try {
      await video.play();
    } catch {
      // Autoplay of a muted inline video is allowed; a refusal here still
      // leaves frames to read once the element starts.
    }

    let detect: Detect;
    try {
      detect = (await createDetector()).detect;
    } catch {
      stopCamera();
      setPhase('unavailable');
      return;
    }

    const tick = async () => {
      if (run !== runRef.current) return;
      let value: string | null = null;
      try {
        value = await detect(video);
      } catch {
        value = null;
      }
      if (run !== runRef.current) return;
      if (value !== null) {
        const result = parseVerifyInput(value, window.location.origin);
        if (result.ok) {
          go(result);
          return;
        }
        if (lastRefusedRef.current !== value) {
          lastRefusedRef.current = value;
          setRefusal(result.reason === 'empty' ? 'invalid' : result.reason);
        }
      }
      timerRef.current = setTimeout(tick, SCAN_INTERVAL_MS);
    };
    void tick();
  }, [go, stopCamera]);

  const typeInstead = () => {
    stopCamera();
    setRefusal(null);
    setPhase('manual');
  };

  const submitTyped = (event: React.FormEvent) => {
    event.preventDefault();
    const result = parseVerifyInput(typed, window.location.origin);
    if (result.ok) {
      go(result);
    } else {
      setRefusal(result.reason === 'empty' ? 'invalid' : result.reason);
    }
  };

  const refusalText =
    refusal === 'foreign'
      ? t(
          'verifyScan.refused.foreign',
          'This code is not a document check from {site}. For your safety, only {site} links are opened here.',
          { site: siteName }
        )
      : refusal === 'invalid'
        ? t('verifyScan.refused.invalid', 'That is not a document code. Check it and try again.')
        : null;

  const showVideo = phase === 'starting' || phase === 'scanning';

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent closeLabel={t('verifyScan.close', 'Close')} data-testid="verify-document-dialog">
        <DialogHeader>
          <DialogTitle>{t('verifyScan.title', 'Verify a document')}</DialogTitle>
          <DialogDescription>
            {phase === 'manual'
              ? t(
                  'verifyScan.manual.description',
                  'Paste the link from the document, or type the 64-character code from the end of that link.'
                )
              : t(
                  'verifyScan.description',
                  'Scan the QR code printed on a document from {site} to check that it is genuine. You do not need an account.',
                  { site: siteName }
                )}
          </DialogDescription>
        </DialogHeader>

        {phase === 'intro' && (
          <div className="space-y-2 rounded-control-lg bg-muted p-4 text-sm text-muted-foreground">
            <p className="font-medium text-foreground">
              {t('verifyScan.camera.why', 'Your browser will ask to use the camera.')}
            </p>
            <p>
              {t(
                'verifyScan.camera.privacy',
                'The picture stays on this device: only the code is read, and the camera turns off as soon as the code is found or you close this window.'
              )}
            </p>
          </div>
        )}

        {/* Kept mounted while starting so the stream has somewhere to go. */}
        <div className={showVideo ? 'relative overflow-hidden rounded-control-lg bg-foreground' : 'hidden'}>
          <video
            ref={videoRef}
            className="aspect-square w-full object-cover"
            playsInline
            muted
            aria-label={t('verifyScan.camera.preview', 'Camera preview')}
            data-testid="verify-scan-video"
          />
          {/* Dashed: waiting for a code, not found yet. */}
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-[18%] rounded-dialog border-2 border-dashed border-card/90"
          />
        </div>
        {showVideo && (
          <p className="text-sm text-muted-foreground" role="status" aria-live="polite">
            {phase === 'starting'
              ? t('verifyScan.camera.starting', 'Starting the camera…')
              : t('verifyScan.camera.aim', 'Hold the QR code inside the frame. It is read automatically.')}
          </p>
        )}

        {phase === 'denied' && (
          <Alert variant="destructive">
            <AlertDescription>
              {t(
                'verifyScan.camera.denied',
                'The camera is blocked for this site. Allow it in your browser’s site settings and try again, or type the code instead.'
              )}
            </AlertDescription>
          </Alert>
        )}
        {phase === 'unavailable' && (
          <Alert>
            <AlertDescription>
              {t(
                'verifyScan.camera.unavailable',
                'No camera is available here. Type the code instead, or open the link with your phone’s camera.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {phase === 'manual' && (
          <form id="verify-typed" onSubmit={submitTyped} className="space-y-2">
            <label htmlFor="verify-typed-input" className="text-sm font-medium">
              {t('verifyScan.manual.label', 'Link or code')}
            </label>
            <Input
              id="verify-typed-input"
              dir="ltr"
              autoFocus
              autoComplete="off"
              spellCheck={false}
              value={typed}
              onChange={(e) => {
                setTyped(e.target.value);
                if (refusal) setRefusal(null);
              }}
              className="font-mono"
              placeholder="https://…/verify/…"
            />
          </form>
        )}

        {refusalText && (
          <Alert variant="destructive" data-testid="verify-scan-refusal">
            <AlertDescription>{refusalText}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          {phase === 'manual' ? (
            <>
              <Button type="button" variant="outline" onClick={() => void startCamera()}>
                <IconCamera aria-hidden />
                {t('verifyScan.useCamera', 'Use the camera')}
              </Button>
              <Button type="submit" form="verify-typed">
                {t('verifyScan.manual.submit', 'Check document')}
              </Button>
            </>
          ) : (
            <>
              <Button type="button" variant="outline" onClick={typeInstead}>
                <IconKeyboard aria-hidden />
                {t('verifyScan.typeInstead', 'Type the code')}
              </Button>
              {!showVideo && (
                <Button type="button" onClick={() => void startCamera()}>
                  <IconCamera aria-hidden />
                  {phase === 'intro'
                    ? t('verifyScan.useCamera', 'Use the camera')
                    : t('verifyScan.tryAgain', 'Try the camera again')}
                </Button>
              )}
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
