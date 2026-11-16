/**
 * The theme picker in a document's settings (`TplDocThemes`, WHIT-785, E.44):
 * what it stores for each choice, the two "Only for this document"
 * overrides, and the note for a theme the workspace no longer has.
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ThemePicker, type ThemeChoice } from '@whity/features/document-editor';
import { themeChoices } from '@/lib/documents/sources';

const THEMES: ThemeChoice[] = [
  { ref: 'tenant.workspace', scope: 'tenant', name: 'Example Bakery', nameAr: 'مخبز المثال', accent: '#b74c05', isDefault: true },
  { ref: 'whity.standard', scope: 'system', name: 'Whity standard', nameAr: 'نسق Whity القياسي', isDefault: false },
  { ref: 'tenant.winter', scope: 'tenant', name: 'Winter offers', nameAr: 'عروض الشتاء', accent: '#2f3a45', isDefault: false },
];

async function open(value: Parameters<typeof ThemePicker>[0]['value'], onChange = jest.fn()) {
  const user = userEvent.setup();
  render(<ThemePicker value={value} themes={THEMES} onChange={onChange} />);
  await user.click(screen.getByRole('button', { name: /^Theme:/ }));
  return { user, onChange };
}

describe('ThemePicker', () => {
  it('shows the workspace default for a document without a theme of its own', async () => {
    await open(undefined);
    expect(screen.getByRole('button', { name: 'Theme: Example Bakery' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: /Example Bakery/ })).toHaveAttribute('aria-checked', 'true');
  });

  it('stores another theme by its ref, and the default as no theme at all', async () => {
    const { user, onChange } = await open(undefined);
    await user.click(screen.getByRole('radio', { name: /Winter offers/ }));
    expect(onChange).toHaveBeenLastCalledWith({ ref: 'tenant.winter' });

    await user.click(screen.getByRole('radio', { name: /Example Bakery/ }));
    expect(onChange).toHaveBeenLastCalledWith(null);
  });

  it('keeps the document’s own letterhead logo when the theme changes', async () => {
    const { user, onChange } = await open({ overrides: { letterhead: { logo: 'none' } } });
    await user.click(screen.getByRole('radio', { name: /Whity standard/ }));
    expect(onChange).toHaveBeenLastCalledWith({ ref: 'whity.standard', overrides: { letterhead: { logo: 'none' } } });
  });

  it('sets the letterhead logo only for this document, and As the theme clears it', async () => {
    const { user, onChange } = await open({ ref: 'tenant.winter' });
    await user.click(screen.getByRole('button', { name: 'Square' }));
    expect(onChange).toHaveBeenLastCalledWith({ ref: 'tenant.winter', overrides: { letterhead: { logo: 'square' } } });
  });

  it('says when the document’s theme is gone and offers the workspace default', async () => {
    const { user, onChange } = await open({ ref: 'acme.classic' });
    expect(screen.getByText('This theme isn’t available any more')).toBeInTheDocument();
    expect(screen.getByText('This draft shows in your workspace theme until it’s back. It can’t be published like this.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Use Example Bakery instead' }));
    expect(onChange).toHaveBeenLastCalledWith(null);
  });
});

describe('themeChoices', () => {
  it('leaves out archived themes and puts the workspace default first', () => {
    const list = themeChoices([
      { ref: 'whity.standard', scope: 'system', name: 'Whity standard', name_ar: 'x', tokens: { accent: '#0961bb' }, archived: false, default_for: [] },
      { ref: 'tenant.old', scope: 'tenant', name: 'Old', name_ar: '', tokens: {}, archived: true, default_for: [] },
      { ref: 'tenant.workspace', scope: 'tenant', name: 'Example Bakery', name_ar: 'مخبز', tokens: { accent: '#b74c05' }, archived: false, default_for: ['workspace'] },
    ]);
    expect(list.map((c) => c.ref)).toEqual(['tenant.workspace', 'whity.standard']);
    expect(list[0]).toMatchObject({ isDefault: true, accent: '#b74c05', nameAr: 'مخبز' });
    expect(themeChoices(null)).toEqual([]);
  });
});
