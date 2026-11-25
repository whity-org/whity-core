/**
 * The postMessage bridge between an embedded plugin screen and the app (#1688).
 *
 * An `embed` screen runs in a sandboxed frame with an opaque origin (WHIT-1217):
 * it cannot read this page, the app's cookies or storage, and its own calls to
 * the API carry no session. This bridge is how it still talks to the app, in a
 * small fixed vocabulary, protocol `whity.frame/1`:
 *
 *   frame → app                         app → frame
 *   { type: 'ready' }                   { type: 'init', locale, dir, theme, apiBase }
 *   { type: 'request', id, method,      { type: 'response', id, ok, status, body }
 *     path, body? }
 *   { type: 'resize', height }          (nothing)
 *   { type: 'navigate', to }            (nothing)
 *   { type: 'toast', message, tone? }   (nothing)
 *
 * Every message carries `whity: 'frame/1'`. Anything else is ignored, and so is
 * any message whose `source` is not this frame's window.
 *
 * REQUESTS. The app makes the call for the frame, to an `/api/` path only, with
 * a five-minute token bound to that plugin's routes
 * (`POST /api/plugin-frames/{feature}/token`, `PluginFrameTokens` on the server)
 * and with cookies left out. So the frame reaches its own plugin's routes, as the
 * person and within their permissions, and nothing else: a core route or another
 * plugin's route answers 401. The token stays in this page; the frame never sees
 * it.
 *
 * Replies are posted with target origin `'*'` because an opaque origin cannot be
 * named; they go to this frame's window object only.
 */

export const FRAME_PROTOCOL = 'frame/1';

/** Frame heights the app accepts, in CSS pixels. */
export const FRAME_MIN_HEIGHT = 120;
export const FRAME_MAX_HEIGHT = 8000;

const METHODS = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
const TONES = new Set(['success', 'error', 'warning', 'info'] as const);
type Tone = 'success' | 'error' | 'warning' | 'info';

/** Refresh the token when it has less than this many seconds left. */
const TOKEN_MARGIN_SECONDS = 30;

/** Longest toast text the app shows from a frame. */
const TOAST_MAX = 300;

export interface FrameToken {
  token: string;
  expiresIn: number;
}

export interface FrameBridgeOptions {
  /** The frame's window, or null before it loads. */
  frameWindow: () => Window | null;
  /** The screen's embed path, e.g. `/api/v1/bom/designer`; gives the API version. */
  embedPath: string;
  /** Ask the server for a frame token (the person's own session call). */
  fetchToken: () => Promise<FrameToken>;
  /** The network call; `fetch` in the app, a stub in tests. */
  send: (url: string, init: RequestInit) => Promise<Response>;
  context: () => {
    locale: string;
    dir: 'ltr' | 'rtl';
    theme: 'light' | 'dark';
    /** SDK 1.173: `customer` inside a workspace's customer area. */
    audience?: 'staff' | 'customer';
    /** SDK 1.173: the workspace's brand, so a frame can match its look. */
    brand?: { name: string; color: string | null; logo: string | null };
  };
  onResize: (height: number) => void;
  onNavigate: (to: string) => void;
  onToast: (message: string, tone: Tone) => void;
  /** Seconds since the epoch; injectable for tests. */
  now?: () => number;
}

/** `/api/v1` from `/api/v1/bom/designer`; `/api` when the path has no version. */
export function apiBaseOf(embedPath: string): string {
  const m = /^\/api\/(v\d+)\//.exec(embedPath);
  return m ? `/api/${m[1]}` : '/api';
}

/**
 * The API path a frame may ask for, versioned like its embed path, or null.
 * Only same-site `/api/` paths: no scheme, no `//`, no `..`, no backslash.
 */
export function framePath(path: unknown, embedPath: string): string | null {
  if (typeof path !== 'string' || path.length > 2048) return null;
  if (!path.startsWith('/api/') || path.includes('//') || path.includes('\\')) return null;
  if (/(^|\/)\.\.?(\/|$|\?)/.test(path.split('#')[0] ?? '')) return null;
  if (/[\s\u0000-\u001f]/.test(path) || /%2e/i.test(path)) return null;
  if (/^\/api\/v\d+\//.test(path)) return path;
  const base = apiBaseOf(embedPath);
  return base === '/api' ? path : base + path.slice('/api'.length);
}

/** An in-app path the frame may send the person to, or null. */
export function navigationTarget(to: unknown): string | null {
  if (typeof to !== 'string' || to.length > 2048) return null;
  if (!to.startsWith('/') || to.startsWith('//') || to.includes('\\')) return null;
  if (to.startsWith('/api/') || /[\s\u0000-\u001f]/.test(to)) return null;
  return to;
}

type Message = Record<string, unknown>;

function isFrameMessage(data: unknown): data is Message {
  return typeof data === 'object' && data !== null && (data as Message).whity === FRAME_PROTOCOL;
}

/**
 * Build the `message` listener for one embedded screen. Attach it to `window`
 * and remove it when the screen goes.
 */
export function createFrameBridge(options: FrameBridgeOptions): (event: MessageEvent) => void {
  const now = options.now ?? (() => Date.now() / 1000);
  let cached: { token: string; until: number } | null = null;
  let pending: Promise<string> | null = null;

  const token = async (): Promise<string> => {
    if (cached && cached.until - TOKEN_MARGIN_SECONDS > now()) return cached.token;
    if (!pending) {
      pending = options
        .fetchToken()
        .then((issued) => {
          cached = { token: issued.token, until: now() + issued.expiresIn };
          return issued.token;
        })
        .finally(() => {
          pending = null;
        });
    }
    return pending;
  };

  const reply = (message: Message): void => {
    // '*' is required: the frame is sandboxed with an opaque origin ("null"),
    // which cannot be named as a postMessage target. The message still goes to
    // one window only, this iframe's own contentWindow, and carries nothing
    // secret: `init` is locale, direction, theme and API base; `response` is
    // the status and body of the plugin's own route. The frame token stays in
    // this page (the closure above) and is never posted; a test pins that.
    options.frameWindow()?.postMessage({ whity: FRAME_PROTOCOL, ...message }, '*');
  };

  const request = async (data: Message): Promise<void> => {
    const id = data.id;
    if (typeof id !== 'string' && typeof id !== 'number') return;
    const method = typeof data.method === 'string' ? data.method.toUpperCase() : 'GET';
    const path = framePath(data.path, options.embedPath);
    if (!METHODS.has(method) || path === null) {
      reply({ type: 'response', id, ok: false, status: 0, body: null, error: 'refused' });
      return;
    }

    try {
      const headers: Record<string, string> = { Authorization: `Bearer ${await token()}` };
      const init: RequestInit = { method, headers, credentials: 'omit', cache: 'no-store' };
      if (data.body !== undefined && method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(data.body);
      }
      const response = await options.send(path, init);
      if (response.status === 401) cached = null;
      const text = await response.text();
      let body: unknown = text;
      if ((response.headers.get('Content-Type') ?? '').includes('json')) {
        try {
          body = text === '' ? null : JSON.parse(text);
        } catch {
          body = text;
        }
      }
      reply({ type: 'response', id, ok: response.ok, status: response.status, body });
    } catch {
      reply({ type: 'response', id, ok: false, status: 0, body: null, error: 'network' });
    }
  };

  return (event: MessageEvent): void => {
    const frame = options.frameWindow();
    if (frame === null || event.source !== frame || !isFrameMessage(event.data)) return;
    const data = event.data;

    switch (data.type) {
      case 'ready':
        reply({ type: 'init', ...options.context(), apiBase: apiBaseOf(options.embedPath) });
        return;
      case 'request':
        void request(data);
        return;
      case 'resize': {
        const h = data.height;
        if (typeof h === 'number' && Number.isFinite(h)) {
          options.onResize(Math.round(Math.min(FRAME_MAX_HEIGHT, Math.max(FRAME_MIN_HEIGHT, h))));
        }
        return;
      }
      case 'navigate': {
        const to = navigationTarget(data.to);
        if (to !== null) options.onNavigate(to);
        return;
      }
      case 'toast': {
        if (typeof data.message !== 'string' || data.message.trim() === '') return;
        const tone = TONES.has(data.tone as Tone) ? (data.tone as Tone) : 'info';
        options.onToast(data.message.slice(0, TOAST_MAX), tone);
        return;
      }
      default:
        return;
    }
  };
}
