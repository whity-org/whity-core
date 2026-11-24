/**
 * WHIT-1379: a form reads in its own language's direction. An English form inside
 * the Arabic interface is left to right; an Arabic form inside the English one is
 * right to left. The page around it keeps the interface's direction.
 */

import React, { Suspense } from 'react';
import { act, render, screen } from '@testing-library/react';
import PreviewFormPage from '@/app/(protected)/forms/[id]/preview/page';
import { formContentLanguage } from '@/components/forms/form-fill';
import { contentLanguage, localizedTextLanguage } from '@whity/ui/localized-text';

jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/fetch-like-api').fetchLikeClient());

let mockInterfaceDir: 'ltr' | 'rtl' = 'rtl';
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: mockInterfaceDir }) }));

const client = jest.fn();
beforeEach(() => {
  global.fetch = client as unknown as typeof fetch;
  client.mockReset();
});

describe('contentLanguage', () => {
  it('names the language of the text actually shown', () => {
    expect(localizedTextLanguage({ en: 'Supplier', ar: 'المورد' }, 'ar')).toBe('ar');
    expect(localizedTextLanguage({ en: 'Supplier', ar: ' ' }, 'ar')).toBe('en');
    expect(localizedTextLanguage({ ar: 'المورد' }, 'en')).toBe('ar');
    expect(localizedTextLanguage('المورد', 'en')).toBe('ar');
    expect(localizedTextLanguage([], 'en')).toBeNull();
    expect(localizedTextLanguage(null, 'en')).toBeNull();
  });

  it('counts English shown in place of missing Arabic as English', () => {
    expect(contentLanguage([{ en: 'Supplier' }, { en: 'Crates' }, { en: 'Notes', ar: 'ملاحظات' }], 'ar')).toEqual({ lang: 'en', dir: 'ltr' });
  });

  it('is right to left for Arabic in the English interface', () => {
    expect(contentLanguage([{ ar: 'المورد' }, { ar: 'الصناديق' }], 'en')).toEqual({ lang: 'ar', dir: 'rtl' });
  });

  it('is the reader’s language for a bilingual form, a tie, or nothing to show', () => {
    expect(contentLanguage([{ en: 'Supplier', ar: 'المورد' }], 'ar')).toEqual({ lang: 'ar', dir: 'rtl' });
    expect(contentLanguage([{ en: 'Supplier' }, { ar: 'الصناديق' }], 'ar')).toEqual({ lang: 'ar', dir: 'rtl' });
    expect(contentLanguage([], 'en')).toEqual({ lang: 'en', dir: 'ltr' });
    expect(contentLanguage([null, []], null)).toEqual({ lang: 'en', dir: 'ltr' });
  });

  it('reads a form from its title, questions and section titles', () => {
    const fields = [{ label: { en: 'Supplier' } }, { label: { en: 'Crates' } }];
    expect(formContentLanguage({ title: { en: 'Supplier request' } }, fields, [{ title: { en: 'About' } }], true)).toEqual({ lang: 'en', dir: 'ltr' });
    // An empty title leaves the name to speak for the form.
    expect(formContentLanguage({ title: [] as never, name: { ar: 'طلب مورد' } }, [], [], false)).toEqual({ lang: 'ar', dir: 'rtl' });
  });
});

const json = (status: number, body: unknown) => ({ ok: status < 400, status, json: async () => body });

function previewOf(label: Record<string, string>, name: Record<string, string>) {
  return {
    form: { name, description: null, submit_label: null },
    fields: [
      { field_key: 'supplier', field_type: 'text', label, help_text: null, is_required: true, options: [], multi_valued: false, position: 1 },
    ],
    sections: [{ key: null, field_keys: ['supplier'] }],
    preview: true,
  };
}

async function open(body: unknown) {
  client.mockImplementation(async (url: string) => (url.endsWith('/preview') ? json(200, { data: body }) : json(404, {})));
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <PreviewFormPage params={Promise.resolve({ id: '7' })} />
      </Suspense>
    );
  });
}

describe('a form inside the interface', () => {
  it('keeps an English form left to right in the Arabic interface', async () => {
    mockInterfaceDir = 'rtl';
    await open(previewOf({ en: 'Supplier' }, { en: 'Supplier request' }));

    const input = await screen.findByLabelText(/Supplier/);
    const block = input.closest('[data-content-direction]');
    expect(block).toHaveAttribute('dir', 'ltr');
    expect(block).toHaveAttribute('lang', 'en');
    expect(screen.getByRole('heading', { name: 'Supplier request' })).toHaveAttribute('dir', 'ltr');
  });

  it('reads an Arabic form right to left in the English interface', async () => {
    mockInterfaceDir = 'ltr';
    await open(previewOf({ ar: 'المورد' }, { ar: 'طلب مورد' }));

    const block = (await screen.findByLabelText(/المورد/)).closest('[data-content-direction]');
    expect(block).toHaveAttribute('dir', 'rtl');
    expect(block).toHaveAttribute('lang', 'ar');
  });

  it('follows the interface when the form has both languages', async () => {
    mockInterfaceDir = 'rtl';
    await open(previewOf({ en: 'Supplier', ar: 'المورد' }, { en: 'Supplier request', ar: 'طلب مورد' }));

    const block = (await screen.findByLabelText(/المورد/)).closest('[data-content-direction]');
    expect(block).toHaveAttribute('dir', 'rtl');
  });
});
