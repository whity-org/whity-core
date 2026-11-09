/**
 * The picture at the top of a form (WHIT-944): patterns drawn from the brand colour
 * keep the title readable, and the builder saves the title and banner without
 * losing anything else. Example Bakery's supplier form.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { FormBuilderScreen, FormHeader } from '@whity/features/forms';
import type { FormsScreenEnv } from '@whity/features/forms';
import {
  BANNER_STYLES,
  bannerArt,
  bannerOptions,
  contrast,
  hexToRgb,
  type Rgb,
} from '../../packages/features/src/forms/banner-art';
import { MAIN_COLOUR_SWATCHES } from '@/components/branding-main-colour';

/** Every colour a picture paints with. */
function coloursIn(svg: string): Rgb[] {
  return [...svg.matchAll(/(?:fill|stop-color)="(#[0-9a-f]{6})"/g)].map((m) => hexToRgb(m[1]) as Rgb);
}

describe('banner art', () => {
  // The workspace swatches, the dark-mode tints a theme can give them, and the
  // hard cases: near white, near black, a pure yellow and a pure cyan.
  const brands = [...MAIN_COLOUR_SWATCHES, '#a9c7ff', '#ffd27a', '#fefefe', '#050505', '#ffff00', '#00ffff'];

  it.each(brands)('keeps the title at 4.5:1 or more on every colour drawn from %s', (hex) => {
    const brand = hexToRgb(hex) as Rgb;
    for (const style of BANNER_STYLES) {
      for (const seed of [0, 1, 42, 4821, 2_000_000_000]) {
        const art = bannerArt(brand, style, seed);
        const text = hexToRgb(art.text) as Rgb;
        const colours = coloursIn(art.svg);

        expect(colours.length).toBeGreaterThan(0);
        for (const colour of colours) {
          expect(contrast(text, colour)).toBeGreaterThanOrEqual(4.5);
        }
      }
    }
  });

  it('draws the same picture from the same colour, style and seed', () => {
    const brand = hexToRgb('#b74c05') as Rgb;

    expect(bannerArt(brand, 'mesh', 7).svg).toBe(bannerArt(brand, 'mesh', 7).svg);
    expect(bannerArt(brand, 'mesh', 7).svg).not.toBe(bannerArt(brand, 'mesh', 8).svg);
  });

  it('offers one option per style, and new ones from a new start', () => {
    const first = bannerOptions(1);

    expect(first.map((o) => o.style)).toEqual([...BANNER_STYLES]);
    expect(bannerOptions(2).map((o) => o.seed)).not.toEqual(first.map((o) => o.seed));
  });
});

describe('FormHeader', () => {
  it('is the plain heading without a banner', () => {
    render(<FormHeader title="Sell flour to Example Bakery" description="We reply within a week." />);

    expect(screen.getByRole('heading', { name: 'Sell flour to Example Bakery' })).toBeTruthy();
    expect(screen.queryByTestId('form-banner')).toBeNull();
  });

  it('puts the title over a drawn banner in the colour chosen for it', () => {
    render(<FormHeader title="Sell flour" banner={{ kind: 'pattern', style: 'waves', seed: 3 }} />);

    const heading = screen.getByRole('heading', { name: 'Sell flour' });
    expect(screen.getByTestId('form-banner').style.backgroundImage).toContain('data:image/svg+xml');
    // Whity Blue, the colour a page with no theme draws with, takes white.
    expect(heading.style.color).toBe('rgb(255, 255, 255)');
  });
});

const FORM = {
  id: 7,
  form_key: 'supplier',
  name: { en: 'Supplier form' },
  title: null,
  banner: null,
  description: null,
  description_i18n: {},
  submit_label: null,
  reference_format: null,
  status: 'draft',
  version: 1,
  route_template_id: null,
  created_at: '2026-11-01 10:00:00',
  updated_at: '2026-11-02 10:00:00',
  public_enabled: false,
  public_slug: null,
  public_url: null,
  public_opens_at: null,
  public_closes_at: null,
  // No section titles stored: the sections exist only as the questions' keys.
  sections: [],
};

type Call = { method: string; path: string; body?: unknown };

function question(key: string, section: string) {
  return {
    id: key === 'supplier' ? 1 : 2,
    field_key: key,
    field_type: 'text',
    label: { en: key },
    help_text_i18n: {},
    is_required: false,
    options: [],
    validation: {},
    prefill_source: null,
    section_key: section,
    visible_when: null,
    audience: 'applicant',
  };
}

function fakeEnv(calls: Call[]): FormsScreenEnv {
  return {
    transport: {
      async request(method: string, path: string, body?: unknown) {
        calls.push({ method, path, body });
        if (method === 'GET' && path === '/api/v1/forms/7') return { status: 200, body: { data: FORM } };
        if (method === 'GET' && path === '/api/v1/forms/7/fields') {
          return {
            status: 200,
            body: { data: [question('supplier', 'about'), question('crates', 'goods')], meta: { field_types: ['text'] } },
          };
        }
        if (method === 'PATCH') return { status: 200, body: { data: { ...FORM, ...(body as object) } } };
        if (method === 'PUT') return { status: 200, body: { data: (body as { fields: unknown[] }).fields } };
        if (method === 'POST' && path === '/api/v1/forms/7/banner') {
          return { status: 200, body: { data: { banner: { kind: 'image', image_url: '/api/v1/branding/asset/1/form-banner-7-0123456789abcdef.png' } } } };
        }
        return { status: 404, body: null };
      },
    },
    notify: jest.fn(),
    navigate: jest.fn(),
  };
}

async function openSettings(calls: Call[]) {
  render(<FormBuilderScreen env={fakeEnv(calls)} formId={7} canManage />);
  await screen.findAllByText(/supplier/);
  await userEvent.click(screen.getByRole('tab', { name: 'Settings' }));
}

describe('FormBuilderScreen settings', () => {
  it('saves a title and a drawn banner, and keeps sections that have no title', async () => {
    const calls: Call[] = [];
    await openSettings(calls);

    await userEvent.type(screen.getByLabelText(/English/, { selector: '#form-title-en' }), 'Sell flour to Example Bakery');
    await userEvent.click(screen.getByRole('radio', { name: 'Waves' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true));
    const patch = calls.find((c) => c.method === 'PATCH')?.body as Record<string, unknown>;
    expect(patch.title).toEqual({ en: 'Sell flour to Example Bakery' });
    expect(patch.banner).toMatchObject({ kind: 'pattern', style: 'waves' });
    expect((patch.sections as Array<{ key: string }>).map((s) => s.key)).toEqual(['about', 'goods']);
    // Saving the settings must not take the questions out of their sections.
    const put = calls.find((c) => c.method === 'PUT');
    if (put !== undefined) {
      expect((put.body as { fields: Array<{ section_key: string }> }).fields.map((f) => f.section_key)).toEqual(['about', 'goods']);
    }
  });

  it('uploads a picture as a multipart body, and shows it as chosen without a save', async () => {
    const calls: Call[] = [];
    await openSettings(calls);

    const file = new File([new Uint8Array([0x89, 0x50, 0x4e, 0x47])], 'flour.png', { type: 'image/png' });
    await userEvent.upload(screen.getByTestId('banner-file'), file);

    await waitFor(() => expect(calls.some((c) => c.path === '/api/v1/forms/7/banner')).toBe(true));
    expect(calls.find((c) => c.path === '/api/v1/forms/7/banner')?.body).toBeInstanceOf(FormData);
    expect(await screen.findByRole('radio', { name: 'Your picture' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  });

  it('refuses a picture that is not a PNG, JPEG or WebP before uploading it', async () => {
    const calls: Call[] = [];
    await openSettings(calls);

    const file = new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' });
    await userEvent.upload(screen.getByTestId('banner-file'), file, { applyAccept: false });

    expect(await screen.findByRole('alert')).toHaveTextContent('Use a PNG, JPEG or WebP picture.');
    expect(calls.some((c) => c.path === '/api/v1/forms/7/banner')).toBe(false);
  });
});
