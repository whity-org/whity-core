/**
 * The editor on a phone (PW.6): two-line header with Publish, the mode switch
 * and More tools as bottom sheets, and the toolbar docked above the keyboard.
 */
import * as React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';

import { DocEditor } from '@whity/ui/editor/doc-editor';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';

type Listener = () => void;

function mockPhone(matches: boolean) {
  window.matchMedia = ((query: string) => ({
    matches: query.includes('max-width: 639') ? matches : false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    onchange: null,
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia;
}

function mockViewport(height: number) {
  const listeners: Listener[] = [];
  const vv = {
    height,
    offsetTop: 0,
    addEventListener: (_: string, l: Listener) => listeners.push(l),
    removeEventListener: () => {},
  };
  Object.defineProperty(window, 'visualViewport', { value: vv, configurable: true });
  return {
    resize(h: number) {
      vv.height = h;
      listeners.forEach((l) => l());
    },
  };
}

const publish = { publishedVersion: null, check: async () => [], publish: async () => {} };

describe('the editor on a phone', () => {
  afterEach(() => {
    Object.defineProperty(window, 'visualViewport', { value: undefined, configurable: true });
  });

  it('docks the toolbar at the bottom, shows Publish in the header and opens the mode sheet', async () => {
    mockPhone(true);
    render(<DocEditor document={SAMPLE_DOCUMENT} onChange={() => {}} canEdit publish={publish} />);
    const bar = await screen.findByRole('toolbar');
    expect(bar).toHaveAttribute('data-phone', 'true');
    expect(screen.getByRole('button', { name: 'Publish' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^Mode: / }));
    expect(await screen.findByRole('radiogroup', { name: 'Mode' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: /Viewing/ })).toBeInTheDocument();
  });

  it('opens More tools as a sheet', async () => {
    mockPhone(true);
    render(<DocEditor document={SAMPLE_DOCUMENT} onChange={() => {}} canEdit publish={publish} />);
    await screen.findByRole('toolbar');
    fireEvent.click(screen.getByRole('button', { name: 'More tools' }));
    expect(await screen.findByRole('heading', { name: 'More tools' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Table' })).toBeInTheDocument();
  });

  it('rides the toolbar on top of the on-screen keyboard', async () => {
    mockPhone(true);
    const vv = mockViewport(window.innerHeight);
    render(<DocEditor document={SAMPLE_DOCUMENT} onChange={() => {}} canEdit publish={publish} />);
    const bar = await screen.findByRole('toolbar');
    expect(bar.style.insetBlockEnd).toMatch(/^0(px)?$/);
    act(() => vv.resize(window.innerHeight - 300));
    expect(bar.style.insetBlockEnd).toBe('300px');
  });

  it('keeps the desktop toolbar and header off a wide screen', async () => {
    mockPhone(false);
    render(<DocEditor document={SAMPLE_DOCUMENT} onChange={() => {}} canEdit publish={publish} />);
    const bar = await screen.findByRole('toolbar');
    expect(bar).not.toHaveAttribute('data-phone');
    expect(screen.queryByRole('button', { name: 'More tools' })).toBeNull();
  });
});
