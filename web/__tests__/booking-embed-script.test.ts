/**
 * The booking widget (WHIT-1445, AP.24) and its messages, after CodeQL's two
 * findings on #2509:
 *
 * - js/xss-through-dom: embed.js builds the frame's address with URL from a
 *   checked handle on its own origin, and every element with createElement
 *   and textContent. A hostile `data-workspace`, `data-label` or script
 *   address never reaches the page as HTML or as a script URL.
 * - wildcard-postmessage: the booking page sends what happened only to the
 *   website named in `?parent=`, and only when it is on the workspace's Your
 *   website list; never to `'*'`. embed.js acts only on messages from its own
 *   origin and its own frame.
 */
import fs from 'node:fs';
import path from 'node:path';
import { tellEmbedder } from '@/lib/booking/embed-message';

const SCRIPT = fs.readFileSync(path.join(__dirname, '..', 'public', 'embed.js'), 'utf8');

function load(attributes: Record<string, string>, src = 'https://app.example.com/embed.js'): HTMLScriptElement {
  delete (window as unknown as { WhityBooking?: unknown }).WhityBooking;
  document.body.innerHTML = '';
  const tag = document.createElement('script');
  tag.setAttribute('src', src);
  for (const [k, v] of Object.entries(attributes)) tag.setAttribute(k, v);
  document.body.appendChild(tag);
  Object.defineProperty(document, 'currentScript', { configurable: true, get: () => tag });
  new Function(SCRIPT)();
  Object.defineProperty(document, 'currentScript', { configurable: true, get: () => null });
  return tag;
}

function widget(): { open: () => void; close: () => void } | undefined {
  return (window as unknown as { WhityBooking?: { open: () => void; close: () => void } }).WhityBooking;
}

describe('embed.js', () => {
  it('opens the booking page on its own origin, naming this website as the parent', () => {
    load({ 'data-workspace': '@example-bakery' });
    expect(document.querySelector('.whity-book-button')?.textContent).toBe('Book a time');
    widget()!.open();
    const frame = document.querySelector('[role="dialog"] iframe') as HTMLIFrameElement;
    const url = new URL(frame.getAttribute('src') ?? '');
    expect(url.origin).toBe('https://app.example.com');
    expect(url.pathname).toBe('/book/example-bakery/embed');
    expect(url.searchParams.get('parent')).toBe(window.location.origin);
  });

  it('builds nothing from a hostile handle or script address', () => {
    for (const bad of ['"><img src=x onerror=alert(1)>', 'javascript:alert(1)', '../admin', 'a/b', '']) {
      load({ 'data-workspace': bad });
      expect(widget()).toBeUndefined();
      expect(document.querySelector('img, iframe, .whity-book-button')).toBeNull();
    }
    load({ 'data-workspace': 'example-bakery' }, 'javascript:alert(1)');
    expect(widget()).toBeUndefined();
  });

  it('shows a label as text, never as HTML', () => {
    load({ 'data-workspace': 'example-bakery', 'data-label': '<img src=x onerror=alert(1)>Book' });
    const button = document.querySelector('.whity-book-button')!;
    expect(button.textContent).toBe('<img src=x onerror=alert(1)>Book');
    expect(button.querySelector('img')).toBeNull();
  });

  it('acts only on messages from its own origin and its own frame', () => {
    load({ 'data-workspace': 'example-bakery' });
    widget()!.open();
    const frame = document.querySelector('[role="dialog"] iframe') as HTMLIFrameElement;
    const send = (origin: string, source: MessageEventSource | null) =>
      window.dispatchEvent(new MessageEvent('message', { data: { type: 'whity-booking', event: 'close' }, origin, source }));

    send('https://evil.example.com', frame.contentWindow);
    send('https://app.example.com', window);
    expect(document.querySelector('[role="dialog"]')).not.toBeNull();
    send('https://app.example.com', frame.contentWindow);
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });
});

describe('the booking page tells only a listed website', () => {
  const list = async () => 'https://example-bakery.com https://shop.example-bakery.com';

  it('posts to the parent origin when it is on the list, never to *', async () => {
    const parent = { postMessage: jest.fn() };
    expect(await tellEmbedder('example-bakery', 'booked', { search: '?parent=https%3A%2F%2Fexample-bakery.com', parent, frameAncestors: list })).toBe(true);
    expect(parent.postMessage).toHaveBeenCalledWith({ type: 'whity-booking', event: 'booked' }, 'https://example-bakery.com');
    expect(parent.postMessage.mock.calls.every(([, target]) => target !== '*')).toBe(true);
  });

  it('sends nothing to a website that is not listed, without a parent, or when the list is unreadable', async () => {
    const parent = { postMessage: jest.fn() };
    await tellEmbedder('example-bakery', 'booked', { search: '?parent=https%3A%2F%2Fevil.example.com', parent, frameAncestors: list });
    await tellEmbedder('example-bakery', 'booked', { search: '?parent=*', parent, frameAncestors: async () => '*' });
    await tellEmbedder('example-bakery', 'booked', { search: '', parent, frameAncestors: list });
    await tellEmbedder('example-bakery', 'booked', { search: '?parent=https%3A%2F%2Fexample-bakery.com', parent, frameAncestors: async () => "'none'" });
    await tellEmbedder('example-bakery', 'booked', {
      search: '?parent=https%3A%2F%2Fexample-bakery.com',
      parent,
      frameAncestors: async () => {
        throw new Error('down');
      },
    });
    expect(await tellEmbedder('example-bakery', 'booked', { search: '?parent=https%3A%2F%2Fexample-bakery.com', parent: null, frameAncestors: list })).toBe(false);
    expect(parent.postMessage).not.toHaveBeenCalled();
  });
});
