/**
 * The install offer (PwInstall, PW.11): not in an installed app, made once,
 * the browser's prompt on Android, three steps on iPhone Safari.
 */

import React from 'react';
import { act, render, screen } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { InstallOffer } from '@/components/pwa/install-offer';
import {
  INSTALL_DISMISSED_KEY,
  resetInstallOfferForTests,
  startInstallOffer,
} from '@/lib/pwa/install-offer';

const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const IPHONE_CHROME =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1';
const ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

function setUserAgent(ua: string) {
  Object.defineProperty(window.navigator, 'userAgent', { value: ua, configurable: true });
}

function setStandalone(on: boolean) {
  window.matchMedia = ((query: string) => ({
    matches: on && query.includes('standalone'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
    onchange: null,
  })) as unknown as typeof window.matchMedia;
}

function promptEvent() {
  const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
    prompt: jest.Mock;
    userChoice: Promise<{ outcome: string }>;
  };
  event.prompt = jest.fn().mockResolvedValue(undefined);
  event.userChoice = Promise.resolve({ outcome: 'accepted' });
  return event;
}

beforeEach(() => {
  resetInstallOfferForTests();
  window.localStorage.clear();
  setStandalone(false);
  setUserAgent(ANDROID);
  Object.defineProperty(window.navigator, 'standalone', { value: undefined, configurable: true });
});

async function mount() {
  await act(async () => {
    startInstallOffer(true, 'Example Bakery', null);
  });
  render(<InstallOffer />);
}

describe('install offer', () => {
  it('is not shown in an installed app (display-mode standalone)', async () => {
    setUserAgent(IPHONE);
    setStandalone(true);
    await mount();
    expect(screen.queryByTestId('install-offer')).toBeNull();
  });

  it('is not shown in an installed iPhone app (navigator.standalone)', async () => {
    setUserAgent(IPHONE);
    Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
    await mount();
    expect(screen.queryByTestId('install-offer')).toBeNull();
  });

  it('is not shown on a desktop browser without a prompt', async () => {
    setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');
    await mount();
    expect(screen.queryByTestId('install-offer')).toBeNull();
  });

  it('is not shown when installing is switched off', async () => {
    setUserAgent(IPHONE);
    await act(async () => startInstallOffer(false));
    render(<InstallOffer />);
    expect(screen.queryByTestId('install-offer')).toBeNull();
  });

  it('shows the three steps on iPhone Safari, and asks only once', async () => {
    setUserAgent(IPHONE);
    await mount();
    const row = await screen.findByTestId('install-offer');
    expect(row).toHaveTextContent('Install Example Bakery on this phone');
    await userEvent.click(row);
    expect(await screen.findByText('Tap Share at the bottom of Safari')).toBeInTheDocument();
    expect(screen.getByText('Choose “Add to Home Screen”')).toBeInTheDocument();
    expect(screen.getByText('Tap Add')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Not now' }));
    expect(screen.queryByTestId('install-offer')).toBeNull();
    expect(window.localStorage.getItem(INSTALL_DISMISSED_KEY)).toBe('1');

    // A later visit in the same browser does not offer it again.
    resetInstallOfferForTests();
    await mount();
    expect(screen.queryAllByTestId('install-offer')).toHaveLength(0);
  });

  it('does not offer steps in another browser on iPhone', async () => {
    setUserAgent(IPHONE_CHROME);
    await mount();
    expect(screen.queryByTestId('install-offer')).toBeNull();
  });

  it('uses the browser prompt on Android, then remembers the answer', async () => {
    await mount();
    expect(screen.queryByTestId('install-offer')).toBeNull();
    const event = promptEvent();
    await act(async () => {
      window.dispatchEvent(event);
    });
    expect(event.defaultPrevented).toBe(true);
    const row = await screen.findByTestId('install-offer');
    await userEvent.click(row);
    expect(event.prompt).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('install-offer')).toBeNull();
    expect(screen.queryByTestId('install-sheet')).toBeNull();
    expect(window.localStorage.getItem(INSTALL_DISMISSED_KEY)).toBe('1');
  });

  it('keeps working when local storage is blocked', async () => {
    setUserAgent(IPHONE);
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    await mount();
    expect(await screen.findByTestId('install-offer')).toBeInTheDocument();
    jest.restoreAllMocks();
  });
});
