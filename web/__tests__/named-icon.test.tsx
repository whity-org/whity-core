/**
 * WHIT-1245: icons by name without shipping the whole Tabler set.
 */

import React from 'react';
import { render, waitFor } from '@testing-library/react';
import { IconFile, IconUsers } from '@tabler/icons-react';
import { ICONS } from '@whity/ui/icon-map';
import { curatedIcon, namedIcon, tablerComponentName } from '@/lib/named-icon';

// Jest does not transform Tabler's per-icon ESM files; stand in for the one the
// lazy path loads, so this tests the loading logic. The real chunking is
// checked against a production build.
jest.mock(
  '@tabler/icons-react/dist/esm/icons/IconUfo.mjs',
  () => ({ __esModule: true, default: jest.requireActual('@tabler/icons-react').IconUfo }),
  { virtual: true },
);

describe('namedIcon', () => {
  it('normalises kebab, snake and Pascal names to the Tabler export name', () => {
    expect(tablerComponentName('building-community')).toBe('IconBuildingCommunity');
    expect(tablerComponentName('building_community')).toBe('IconBuildingCommunity');
    expect(tablerComponentName('IconUsers')).toBe('IconUsers');
  });

  it("resolves Whity's curated names before Tabler's", () => {
    expect(namedIcon('dashboard', IconFile)).toBe(ICONS.dashboard.icon);
  });

  it('resolves a curated icon by its Tabler name, synchronously', () => {
    expect(curatedIcon('users')).toBe(IconUsers);
    expect(namedIcon('IconUsers', IconFile)).toBe(IconUsers);
  });

  it('falls back for an empty or malformed name', () => {
    expect(namedIcon(undefined, IconFile)).toBe(IconFile);
    expect(namedIcon('  ', IconFile)).toBe(IconFile);
    expect(namedIcon('../../etc', IconFile)).toBe(IconFile);
  });

  it('gives a name outside the curated set one stable component', () => {
    expect(namedIcon('ufo', IconFile)).toBe(namedIcon('ufo', IconFile));
  });

  it('shows the fallback for an unknown icon and keeps it', async () => {
    const Unknown = namedIcon('no-such-icon-anywhere', IconFile);
    const { container } = render(<Unknown data-testid="icon" />);
    expect(container.querySelector('svg.tabler-icon-file')).not.toBeNull();
    await waitFor(() => expect(namedIcon('no-such-icon-anywhere', IconFile)).toBe(IconFile));
    expect(container.querySelector('svg.tabler-icon-file')).not.toBeNull();
  });

  it('loads a non-curated Tabler icon on demand', async () => {
    const Ufo = namedIcon('ufo', IconFile);
    const { container } = render(<Ufo />);
    await waitFor(() => expect(container.querySelector('svg.tabler-icon-ufo')).not.toBeNull());
  });
});
