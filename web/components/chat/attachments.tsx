'use client';

/**
 * Files, photos and voice in Chat (design 3.5, UC.10, UC.11; card
 * `TplChatParts`).
 *
 * - Photos sent together show as an album; a video plays in place; a file is a
 *   card with its type, name, size and Download.
 * - Voice is tap to record and tap Send, nothing held down; it plays in place
 *   with its text under it. The text comes from the browser's own speech
 *   recognition while recording, where the browser has it.
 * - Uploading shows progress on the message; a file that didn't go says "Not
 *   sent" with Try again, and nothing is lost.
 */

import { useEffect, useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { cn } from '@/lib/utils';
import type { ChatAttachment } from '@/lib/chat-wire';

/** The words here are Chat's: the `t` passed in is `useTranslation('chat')`, as this hook gives. */
export function useChatFilesText(): TranslateFn {
  const t = useTranslation('chat');
  return t;
}

/** "1.2 MB", "340 KB". */
export function fileSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** "0:18", "2:05". */
export function duration(ms: number | null | undefined): string {
  const total = Math.max(0, Math.round((ms ?? 0) / 1000));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function extension(a: ChatAttachment): string {
  const fromName = a.filename?.split('.').pop();
  return (fromName && fromName.length <= 4 ? fromName : a.content_type.split('/').pop() ?? 'file').toUpperCase();
}

/** Static bars for a voice message: the same shape every time for the same message. */
function bars(seed: number, count = 28): number[] {
  const out: number[] = [];
  let x = seed || 7;
  for (let i = 0; i < count; i++) {
    x = (x * 1103515245 + 12345) & 0x7fffffff;
    out.push(30 + (x % 70));
  }
  return out;
}

export function VoicePlayer({ t, attachment }: { t: TranslateFn; attachment: ChatAttachment }) {
  const audio = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0);
  const shape = bars(attachment.id);

  return (
    <div className="grid min-w-60 gap-1.5">
      <div className="flex items-center gap-2.5">
        <Button
          type="button"
          size="icon-sm"
          className="rounded-full"
          aria-label={playing ? t('voice.pause', 'Pause') : t('voice.play', 'Play voice message')}
          onClick={() => {
            const el = audio.current;
            if (!el) return;
            if (el.paused) void el.play().catch(() => undefined);
            else el.pause();
          }}
        >
          <Icon name={playing ? 'pause' : 'play'} size={14} />
        </Button>
        <span className="flex h-6 flex-1 items-center gap-[2px]" aria-hidden="true">
          {shape.map((h, i) => (
            <span
              key={i}
              className={cn('w-[3px] rounded-full', i / shape.length < progress ? 'bg-primary' : 'bg-muted-foreground/40')}
              style={{ height: `${h}%` }}
            />
          ))}
        </span>
        <span className="text-xs text-muted-foreground tabular-nums">{duration(attachment.duration_ms)}</span>
      </div>
      {attachment.transcript && (
        <p dir="auto" className="text-start text-sm text-muted-foreground">
          {attachment.transcript}
        </p>
      )}
      <audio
        ref={audio}
        src={attachment.url}
        preload="none"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => {
          setPlaying(false);
          setProgress(0);
        }}
        onTimeUpdate={(e) => {
          const el = e.currentTarget;
          const total = Number.isFinite(el.duration) && el.duration > 0 ? el.duration : (attachment.duration_ms ?? 0) / 1000;
          setProgress(total > 0 ? el.currentTime / total : 0);
        }}
      />
    </div>
  );
}

function FileCard({ t, attachment }: { t: TranslateFn; attachment: ChatAttachment }) {
  return (
    <div className="flex min-w-56 items-center gap-3 rounded-control border border-border bg-card px-3 py-2">
      <span className="grid h-9 min-w-9 place-items-center rounded-control-sm bg-error-tint px-1 text-xs font-semibold text-error-text">{extension(attachment)}</span>
      <span className="min-w-0 flex-1">
        <span dir="auto" className="block truncate text-start text-sm font-medium">
          {attachment.filename}
        </span>
        <span className="block text-xs text-muted-foreground">{fileSize(attachment.byte_size)}</span>
      </span>
      <a
        href={attachment.url}
        download={attachment.filename ?? undefined}
        className="grid size-8 place-items-center rounded-control-sm hover:bg-muted"
        aria-label={t('file.download', 'Download {name}', { name: attachment.filename ?? '' })}
      >
        <Icon name="download" size={16} />
      </a>
    </div>
  );
}

/** Photos and videos as an album: one wide, two side by side, more in a grid. */
function Album({ t, items }: { t: TranslateFn; items: ChatAttachment[] }) {
  const one = items.length === 1;
  return (
    <div className={cn('grid gap-1 overflow-hidden rounded-control', one ? 'grid-cols-1' : 'grid-cols-2')}>
      {items.slice(0, 4).map((a, i) => {
        const more = i === 3 && items.length > 4 ? items.length - 4 : 0;
        const ratio = a.width && a.height ? `${a.width} / ${a.height}` : one ? '4 / 3' : '1 / 1';
        return (
          <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="relative block bg-muted" style={{ aspectRatio: one ? ratio : '1 / 1' }}>
            {a.kind === 'video' ? (
              <video src={a.url} controls preload="metadata" className="size-full object-cover" aria-label={a.filename ?? t('file.video', 'Video')} />
            ) : (
              // eslint-disable-next-line @next/next/no-img-element -- a signed-in file from the API, not a static asset
              <img src={a.url} alt={a.filename ?? t('file.photo', 'Photo')} loading="lazy" className="size-full object-cover" />
            )}
            {more > 0 && (
              <span className="absolute inset-0 grid place-items-center bg-foreground/50 text-lg font-semibold text-background">+{more}</span>
            )}
          </a>
        );
      })}
    </div>
  );
}

/** What a message carries besides its words. */
export function MessageAttachments({ t, attachments }: { t: TranslateFn; attachments: ChatAttachment[] }) {
  if (attachments.length === 0) return null;
  const media = attachments.filter((a) => a.kind === 'image' || a.kind === 'video');
  const files = attachments.filter((a) => a.kind === 'file');
  const voice = attachments.filter((a) => a.kind === 'voice');
  return (
    <div className="grid gap-1.5 py-0.5">
      {voice.map((a) => (
        <VoicePlayer key={a.id} t={t} attachment={a} />
      ))}
      {media.length > 0 && <Album t={t} items={media} />}
      {files.map((a) => (
        <FileCard key={a.id} t={t} attachment={a} />
      ))}
    </div>
  );
}

/** How a message of files only reads in the list and in quotes: "Photo", "Voice message · 0:18". */
export function attachmentsLine(t: TranslateFn, attachments: ChatAttachment[] | undefined): string {
  const list = attachments ?? [];
  if (list.length === 0) return '';
  const voice = list.find((a) => a.kind === 'voice');
  if (voice) return t('file.voiceLine', 'Voice message · {length}', { length: duration(voice.duration_ms) });
  const photos = list.filter((a) => a.kind === 'image' || a.kind === 'video').length;
  if (photos === list.length) return photos === 1 ? t('file.photo', 'Photo') : t('file.photos', '{count} photos', { count: String(photos) });
  return list.length === 1 ? list[0].filename ?? t('file.file', 'File') : t('file.files', '{count} files', { count: String(list.length) });
}

// ── Sending ──────────────────────────────────────────────────────────────────

/** A file on its way: uploading, ready to send, or not sent. */
export interface PendingFile {
  key: string;
  file: Blob;
  name: string;
  kind: 'image' | 'video' | 'file' | 'voice';
  progress: number;
  status: 'uploading' | 'ready' | 'failed';
  error?: string;
  attachment?: ChatAttachment;
  durationMs?: number;
  transcript?: string;
  preview?: string;
}

export function PendingFiles({
  t,
  files,
  onRemove,
  onRetry,
}: {
  t: TranslateFn;
  files: PendingFile[];
  onRemove: (key: string) => void;
  onRetry: (key: string) => void;
}) {
  if (files.length === 0) return null;
  return (
    <ul className="flex flex-wrap gap-2" aria-label={t('file.pending', 'Files to send')}>
      {files.map((f) => (
        <li key={f.key} className={cn('relative grid w-44 gap-1 rounded-control border bg-card p-2 text-sm', f.status === 'failed' ? 'border-destructive' : 'border-border')}>
          <span className="flex items-center gap-2">
            {f.preview ? (
              // eslint-disable-next-line @next/next/no-img-element -- a local preview of a file being sent
              <img src={f.preview} alt="" className="size-9 rounded-control-sm object-cover" />
            ) : (
              <Icon name={f.kind === 'voice' ? 'microphone' : f.kind === 'file' ? 'document' : 'image'} size={18} />
            )}
            <span dir="auto" className="min-w-0 flex-1 truncate text-start">
              {f.kind === 'voice' ? t('file.voiceLine', 'Voice message · {length}', { length: duration(f.durationMs) }) : f.name}
            </span>
            <button type="button" className="grid size-7 place-items-center rounded-control-sm hover:bg-muted" aria-label={t('file.remove', 'Remove {name}', { name: f.name })} onClick={() => onRemove(f.key)}>
              <Icon name="close" size={14} />
            </button>
          </span>
          {f.status === 'uploading' && (
            <span className="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={Math.round(f.progress * 100)} aria-valuemin={0} aria-valuemax={100} aria-label={t('file.sending', 'Sending {name}', { name: f.name })}>
              <span className="block h-full bg-primary" style={{ width: `${Math.round(f.progress * 100)}%` }} />
            </span>
          )}
          {f.status === 'failed' && (
            <span className="flex items-center gap-1.5 text-xs text-error-text">
              <Icon name="error" size={12} />
              <span className="flex-1">{f.error || t('file.notSent', 'Not sent. No connection.')}</span>
              <button type="button" className="font-medium underline" onClick={() => onRetry(f.key)}>
                {t('file.retry', 'Try again')}
              </button>
            </span>
          )}
        </li>
      ))}
    </ul>
  );
}

type Recognition = {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((e: { resultIndex: number; results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal: boolean }> }) => void) | null;
  onerror: (() => void) | null;
  start: () => void;
  stop: () => void;
};

/** The browser's own speech recognition, where it has one (Chrome, Edge, Safari). */
function speechRecognition(): Recognition | null {
  const w = window as unknown as { SpeechRecognition?: new () => Recognition; webkitSpeechRecognition?: new () => Recognition };
  const Ctor = w.SpeechRecognition ?? w.webkitSpeechRecognition;
  return Ctor ? new Ctor() : null;
}

/**
 * Tap the microphone to start and Send to finish (UC.10): nothing needs
 * holding down. While recording, the words are written down where the
 * browser can.
 */
export function VoiceRecorder({
  t,
  onDone,
  onCancel,
  onError,
}: {
  t: TranslateFn;
  onDone: (blob: Blob, durationMs: number, transcript: string) => void;
  onCancel: () => void;
  onError: (message: string) => void;
}) {
  const [elapsed, setElapsed] = useState(0);
  const recorder = useRef<MediaRecorder | null>(null);
  const chunks = useRef<Blob[]>([]);
  const started = useRef(0);
  const words = useRef('');
  const recognition = useRef<Recognition | null>(null);
  const finish = useRef<'send' | 'cancel'>('cancel');

  useEffect(() => {
    let stream: MediaStream | null = null;
    let timer = 0;
    let live = true;
    const begin = async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch {
        onError(t('voice.noMic', 'Whity can’t use your microphone. Allow it in your browser and try again.'));
        return;
      }
      if (!live) {
        stream.getTracks().forEach((track) => track.stop());
        return;
      }
      const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find((m) => typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(m));
      const rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
      rec.ondataavailable = (e) => {
        if (e.data.size > 0) chunks.current.push(e.data);
      };
      rec.onstop = () => {
        stream?.getTracks().forEach((track) => track.stop());
        recognition.current?.stop();
        if (finish.current === 'send') {
          onDone(new Blob(chunks.current, { type: rec.mimeType || 'audio/webm' }), Date.now() - started.current, words.current.trim());
        }
      };
      recorder.current = rec;
      started.current = Date.now();
      rec.start(250);
      timer = window.setInterval(() => {
        const ms = Date.now() - started.current;
        setElapsed(ms);
        if (ms >= 5 * 60 * 1000) {
          finish.current = 'send';
          rec.stop();
        }
      }, 250);
      const speech = speechRecognition();
      if (speech) {
        speech.lang = document.documentElement.lang || navigator.language;
        speech.continuous = true;
        speech.interimResults = false;
        speech.onresult = (e) => {
          for (let i = e.resultIndex; i < e.results.length; i++) {
            if (e.results[i].isFinal) words.current += ` ${e.results[i][0].transcript}`;
          }
        };
        speech.onerror = () => undefined;
        try {
          speech.start();
          recognition.current = speech;
        } catch {
          // No words this time; the voice still goes.
        }
      }
    };
    void begin();
    return () => {
      live = false;
      window.clearInterval(timer);
      if (recorder.current && recorder.current.state !== 'inactive') recorder.current.stop();
      stream?.getTracks().forEach((track) => track.stop());
    };
    // The recorder runs once per mount; onDone and onError are read at the end.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const stop = (how: 'send' | 'cancel') => {
    finish.current = how;
    if (recorder.current && recorder.current.state !== 'inactive') recorder.current.stop();
    if (how === 'cancel') onCancel();
  };

  return (
    <div className="flex items-center gap-3 rounded-card border border-input bg-card px-3 py-2" role="group" aria-label={t('voice.recording', 'Recording a voice message')}>
      <span className="size-2.5 animate-pulse rounded-full bg-destructive" aria-hidden="true" />
      <span className="font-mono text-sm tabular-nums">{duration(elapsed)}</span>
      <span className="flex h-6 flex-1 items-center gap-[2px]" aria-hidden="true">
        {bars(Math.floor(elapsed / 250) + 1, 24).map((h, i) => (
          <span key={i} className="w-[3px] rounded-full bg-primary" style={{ height: `${h}%` }} />
        ))}
      </span>
      <Button type="button" variant="ghost" size="sm" onClick={() => stop('cancel')}>
        {t('voice.cancel', 'Cancel')}
      </Button>
      <Button type="button" size="icon-sm" aria-label={t('voice.send', 'Send voice message')} onClick={() => stop('send')} className="rtl:[&_svg]:-scale-x-100">
        <Icon name="send" size={16} />
      </Button>
    </div>
  );
}
