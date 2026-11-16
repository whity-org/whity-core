/**
 * The phone browser sheet (WHIT-1155): open the installed app, or install it,
 * never inside the app, never twice a visit, "Not now" for 7 days.
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { AppSwitchSheet } from '@/components/pwa/app-switch-sheet';
import {
  APP_SWITCH_SHOWN_KEY,
  APP_SWITCH_SNOOZE_KEY,
  APP_SWITCH_SNOOZE_MS,
  decideAppSwitch,
  type AppSwitchFacts,
} from '@/lib/pwa/app-switch';
import { resetInstallOfferForTests, startInstallOffer } from '@/lib/pwa/install-offer';
import { buildManifest } from '@/lib/pwa/shared';

const base: AppSwitchFacts = {
  enabled: true,
  standalone: false,
  phone: true,
  installed: false,
  canPrompt: false,
  iosSafari: false,
  snoozedUntil: null,
  shownThisVisit: false,
  now: 1_800_000_000_000,
};

describe('what the sheet offers', () => {
  it('nothing inside the installed app', () => {
    expect(decideAppSwitch({ ...base, standalone: true, installed: true, canPrompt: true })).toBeNull();
  });

  it('opening the app when it is installed', () => {
    expect(decideAppSwitch({ ...base, installed: true, canPrompt: true })).toBe('open');
  });

  it('the browser prompt on Android when it is not installed', () => {
    expect(decideAppSwitch({ ...base, canPrompt: true })).toBe('install');
    expect(decideAppSwitch({ ...base, installed: null, canPrompt: true })).toBe('install');
  });

  it('the steps on iPhone Safari', () => {
    expect(decideAppSwitch({ ...base, installed: null, iosSafari: true })).toBe('ios');
  });

  it('nothing on a computer, or where installing is off', () => {
    expect(decideAppSwitch({ ...base, phone: false, canPrompt: true })).toBeNull();
    expect(decideAppSwitch({ ...base, enabled: false, canPrompt: true })).toBeNull();
  });

  it('nothing for 7 days after Not now, then again', () => {
    expect(decideAppSwitch({ ...base, canPrompt: true, snoozedUntil: base.now + 1000 })).toBeNull();
    expect(decideAppSwitch({ ...base, canPrompt: true, snoozedUntil: base.now - 1 })).toBe('install');
  });

  it('never twice in one visit', () => {
    expect(decideAppSwitch({ ...base, canPrompt: true, shownThisVisit: true })).toBeNull();
  });

  it('nothing when there is no way to install (another browser)', () => {
    expect(decideAppSwitch({ ...base, installed: null })).toBeNull();
  });
});

describe('the manifest', () => {
  it('lists itself so the site can ask whether it is installed', () => {
    const m = buildManifest({ state: 'on', siteName: 'Example Bakery', themeColor: '#b74c05', logoSquareUrl: null, version: 'v' }, 'en');
    expect(m.related_applications).toEqual([
      { platform: 'webapp', url: '/manifest.webmanifest' },
      { platform: 'webapp', url: '/manifest.webmanifest?lang=ar' },
    ]);
    expect(m.prefer_related_applications).toBe(false);
  });
});

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

function setPhone(ua: string, standalone = false) {
  Object.defineProperty(window.navigator, 'userAgent', { value: ua, configurable: true });
  window.matchMedia = ((query: string) => ({
    matches: (standalone && query.includes('standalone')) || query.includes('coarse'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia;
}

function setInstalled(apps: { platform: string; url: string }[] | undefined) {
  Object.defineProperty(window.navigator, 'getInstalledRelatedApps', {
    value: apps === undefined ? undefined : async () => apps,
    configurable: true,
  });
}

function prompt() {
  const event = new Event('beforeinstallprompt') as Event & { prompt: jest.Mock; userChoice: Promise<{ outcome: string }> };
  event.prompt = jest.fn(async () => undefined);
  event.userChoice = Promise.resolve({ outcome: 'accepted' });
  return event;
}

describe('the sheet', () => {
  beforeEach(() => {
    resetInstallOfferForTests();
    window.localStorage.clear();
    window.sessionStorage.clear();
    Object.defineProperty(window, 'innerWidth', { value: 390, configurable: true });
    startInstallOffer(true, 'Example Bakery', null);
  });

  it('says to open the installed app, with no button pretending to', async () => {
    setPhone(ANDROID);
    setInstalled([{ platform: 'webapp', url: '/manifest.webmanifest' }]);
    render(<AppSwitchSheet />);
    const sheet = await screen.findByTestId('app-switch');
    expect(sheet.getAttribute('data-offer')).toBe('open');
    expect(sheet.textContent).toContain('Example Bakery is installed on this phone');
    expect(sheet.textContent).toContain('home screen');
    expect(screen.queryByTestId('app-switch-install')).toBeNull();
    expect(window.sessionStorage.getItem(APP_SWITCH_SHOWN_KEY)).toBe('1');
  });

  it('installs on Android with the browser prompt', async () => {
    setPhone(ANDROID);
    setInstalled([]);
    const event = prompt();
    act(() => void window.dispatchEvent(event));
    render(<AppSwitchSheet />);
    await userEvent.click(await screen.findByTestId('app-switch-install'));
    expect(event.prompt).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByTestId('app-switch')).toBeNull());
  });

  it('shows the steps on iPhone and says notifications need the app', async () => {
    setPhone(IPHONE);
    setInstalled(undefined);
    render(<AppSwitchSheet />);
    const sheet = await screen.findByTestId('app-switch');
    expect(sheet.getAttribute('data-offer')).toBe('ios');
    expect(sheet.textContent).toContain('Add to Home Screen');
    expect(sheet.textContent).toContain('notifications only come to the installed app');
  });

  it('Not now waits 7 days on this device', async () => {
    setPhone(IPHONE);
    setInstalled(undefined);
    render(<AppSwitchSheet />);
    await userEvent.click(await screen.findByTestId('app-switch-not-now'));
    expect(screen.queryByTestId('app-switch')).toBeNull();
    const until = Number(window.localStorage.getItem(APP_SWITCH_SNOOZE_KEY));
    expect(until - Date.now()).toBeGreaterThan(APP_SWITCH_SNOOZE_MS - 60_000);
  });

  it('never shows inside the installed app', async () => {
    setPhone(IPHONE, true);
    setInstalled(undefined);
    render(<AppSwitchSheet />);
    await act(async () => undefined);
    expect(screen.queryByTestId('app-switch')).toBeNull();
  });

  it('shows once a visit', async () => {
    setPhone(IPHONE);
    setInstalled(undefined);
    const first = render(<AppSwitchSheet />);
    await screen.findByTestId('app-switch');
    first.unmount();
    render(<AppSwitchSheet />);
    await act(async () => undefined);
    expect(screen.queryByTestId('app-switch')).toBeNull();
  });
});
