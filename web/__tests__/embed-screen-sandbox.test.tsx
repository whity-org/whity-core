/**
 * WHIT-1217 — the embedded plugin screen's frame never gets the app's origin.
 *
 * `allow-scripts` together with `allow-same-origin` on a same-origin page is no
 * sandbox at all: the page can remove its own sandbox and act as the app. The
 * browser half (the page really cannot reach the parent, cookies or the API) is
 * `e2e/plugin-embed-isolation.spec.ts`; this pins the attribute itself.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { EmbedScreen, EMBED_SANDBOX } from '@/components/plugin/embed-screen';
import type { PluginFeature } from '@/lib/plugin-features';

jest.mock('@/components/admin/admin-header', () => ({
  AdminHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

const feature = {
  id: 'bom-designer',
  plugin: 'BillOfMaterials',
  label: 'BOM Designer',
  icon: null,
  group: 'plugins',
  order: 2,
  screen: 'embed',
  resource: null,
  action: null,
  embed: { path: '/api/v1/bom/designer' },
  requiredPermission: 'bom:write',
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
} as unknown as PluginFeature;

describe('EmbedScreen frame (WHIT-1217)', () => {
  it('sandboxes the frame with scripts and forms only, never allow-same-origin', () => {
    render(<EmbedScreen feature={feature} />);
    const frame = screen.getByTitle('BOM Designer');
    const tokens = (frame.getAttribute('sandbox') ?? '').split(/\s+/).filter(Boolean).sort();

    expect(tokens).toEqual(['allow-forms', 'allow-scripts']);
    expect(tokens).not.toContain('allow-same-origin');
    expect(tokens).not.toContain('allow-top-navigation');
    expect(tokens).not.toContain('allow-popups');
    expect(EMBED_SANDBOX).not.toContain('allow-same-origin');
  });

  it('points at the plugin route and sends no referrer', () => {
    render(<EmbedScreen feature={feature} />);
    const frame = screen.getByTitle('BOM Designer');
    expect(frame.getAttribute('src')).toBe('/api/v1/bom/designer');
    expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
  });
});
