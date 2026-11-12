/**
 * Motion (LT.4, foundation F5): the player stays out of the way when it can't
 * play, the Animations setting follows `<html data-motion>` and reduce motion,
 * and Account › Preferences offers Full, Calm and Off.
 */
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { DisplayPreferencesContextProvider, type DisplayPreferencesValue } from '@whity/ui/display-preferences';
import { EmptyFiltered } from '@whity/ui/page-states';
import { Lottie, hasPlayedOnceEver, useMotionMode } from '@whity/ui/lottie';
import { ApprovalQueue } from '@whity/ui/approval-queue';
import { LockedScreen } from '@whity/ui/locked-screen';

import { DisplayPreferencesSettings } from '@/components/display-preferences-settings';
import { FirstVisitScene } from '@/components/first-visit-scene';
import { libraryEmptyState } from '@/app/(protected)/admin/document-library/library-empty-state';

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

describe('Moments in core screens (A13)', () => {
  afterEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  it('plays all-caught-up when the approvals queue is empty', () => {
    render(<ApprovalQueue items={[]} onCurrentChange={() => {}} />);
    expect(document.querySelector('[data-moment="all-caught-up"]')).not.toBeNull();
    expect(screen.getByText('All caught up')).toBeInTheDocument();
  });

  it('shows security-lock on the locked screen, unless the caller brings its own icon', () => {
    const { rerender } = render(<LockedScreen description="Sign in again to continue." />);
    expect(document.querySelector('[data-moment="security-lock"]')).not.toBeNull();
    rerender(<LockedScreen icon={<span>own</span>} description="Sign in again to continue." />);
    expect(document.querySelector('[data-moment="security-lock"]')).toBeNull();
    expect(screen.getByText('own')).toBeInTheDocument();
  });

  it('gives the document library no-results for a search and empty-documents for an empty folder', () => {
    const t = ((_key: string, fallback: string) => fallback) as unknown as Parameters<typeof libraryEmptyState>[0];
    const base = { viewKey: 'all', collection: null, starredCollectionExists: false, total: 0 };
    expect(libraryEmptyState(t, { ...base, searchApplied: true }).moment).toBe('no-results');
    expect(libraryEmptyState(t, { ...base, searchApplied: false }).moment).toBe('empty-documents');
    expect(libraryEmptyState(t, { ...base, searchApplied: false, total: 4 }).moment).toBeUndefined();
  });

  it('shows a first-run scene on the first visit only, whatever the Animations setting', () => {
    document.documentElement.dataset.motion = 'off';
    const first = render(<FirstVisitScene name="brand-sheet-platform" storageKey="test-brand" />);
    expect(document.querySelector('[data-moment="brand-sheet-platform"]')).not.toBeNull();
    first.unmount();
    render(<FirstVisitScene name="brand-sheet-platform" storageKey="test-brand" />);
    expect(document.querySelector('[data-moment="brand-sheet-platform"]')).toBeNull();
    expect(hasPlayedOnceEver('test-brand')).toBe(true);
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
