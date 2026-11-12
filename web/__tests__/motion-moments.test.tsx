/**
 * Motion (LT.4, foundation F5): the player stays out of the way when it can't
 * play, the Animations setting follows `<html data-motion>` and reduce motion,
 * and Account › Preferences offers Full, Calm and Off.
 */
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { DisplayPreferencesContextProvider, type DisplayPreferencesValue } from '@whity/ui/display-preferences';
import { EmptyFiltered } from '@whity/ui/page-states';
import { Lottie, useMotionMode } from '@whity/ui/lottie';

import { DisplayPreferencesSettings } from '@/components/display-preferences-settings';

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string) => fallback,
}));

function setReducedMotion(matches: boolean) {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: (query: string) => ({
      matches: query.includes('reduce') ? matches : false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }),
  });
}

afterEach(() => {
  delete document.documentElement.dataset.motion;
  setReducedMotion(false);
});

describe('Lottie', () => {
  it('is hidden from assistive technology and shows its fallback until the scene is ready', () => {
    render(<Lottie name="no-results" fallback={<span>tile</span>} />);
    const scene = document.querySelector('[data-slot="lottie"]');
    expect(scene).toHaveAttribute('aria-hidden', 'true');
    expect(scene).toHaveAttribute('data-moment', 'no-results');
    expect(screen.getByText('tile')).toBeInTheDocument();
  });

  it('puts no-results in a filtered empty state, with the words and Clear filters still there', () => {
    const onClear = jest.fn();
    render(<EmptyFiltered title="No one matches “Khalda”" clearLabel="Clear filters" onClear={onClear} />);
    expect(document.querySelector('[data-moment="no-results"]')).not.toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(onClear).toHaveBeenCalled();
  });
});

describe('useMotionMode', () => {
  it('follows <html data-motion>', async () => {
    setReducedMotion(false);
    const { result } = renderHook(() => useMotionMode());
    expect(result.current).toBe('full');
    await act(async () => {
      document.documentElement.dataset.motion = 'calm';
    });
    expect(result.current).toBe('calm');
  });

  it('is Off when the device asks for less motion, whatever the setting', () => {
    setReducedMotion(true);
    const { result } = renderHook(() => useMotionMode());
    expect(result.current).toBe('off');
  });
});

describe('Animations in Account › Preferences', () => {
  it('offers Full, Calm and Off and stores the choice', () => {
    const update = jest.fn();
    const value: DisplayPreferencesValue = {
      textSize: 'standard',
      showTechnicalDetails: false,
      animations: 'full',
      update,
    };
    render(
      <DisplayPreferencesContextProvider value={value}>
        <DisplayPreferencesSettings />
      </DisplayPreferencesContextProvider>,
    );
    expect(screen.getByRole('radio', { name: /Full/ })).toBeChecked();
    fireEvent.click(screen.getByRole('radio', { name: /Calm/ }));
    expect(update).toHaveBeenCalledWith({ animations: 'calm' });
    expect(screen.getByText('If your device is set to reduce motion, Whity uses Off.')).toBeInTheDocument();
  });
});
