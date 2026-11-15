import {
  BILINGUAL,
  cleanLocalizedText,
  guessLanguage,
  localizedText,
  sameLocalizedText,
} from '@whity/ui/localized-text';

// The TS twin of Whity\Core\i18n\LocalizedText (WHIT-1038): one fallback rule.
describe('localizedText', () => {
  const names = { ar: 'جامعة عمان الأهلية', en: 'Al-Ahliyya Amman University' };

  it('shows the reader their language', () => {
    expect(localizedText(names, 'en', 'ar')).toBe('Al-Ahliyya Amman University');
    expect(localizedText(names, 'ar', 'ar')).toBe('جامعة عمان الأهلية');
  });

  it('falls back to the original, not to English (#821)', () => {
    expect(localizedText({ ar: 'جامعة' }, 'en', 'ar')).toBe('جامعة');
    expect(localizedText(names, 'fr', 'ar')).toBe('جامعة عمان الأهلية');
  });

  it('without an original reads English, then Arabic, then anything', () => {
    expect(localizedText({ ar: 'مجلس', en: 'Board' }, 'fr')).toBe('Board');
    expect(localizedText({ fr: 'Conseil', ar: 'مجلس' }, 'de')).toBe('مجلس');
    expect(localizedText({ fr: 'Conseil', en: ' ' }, 'ar')).toBe('Conseil');
    expect(localizedText({ en: 'Board', ar: 'مجلس' }, null)).toBe('Board');
  });

  it('takes a plain string, an empty PHP array, and a fallback', () => {
    expect(localizedText(' Legacy ', 'ar')).toBe('Legacy');
    expect(localizedText([], 'ar', null, 'Untitled')).toBe('Untitled');
    expect(localizedText(undefined, 'ar', 'en', 'Example Bakery')).toBe('Example Bakery');
    expect(localizedText({ en: '', ar: null }, 'en', null, 'Untitled')).toBe('Untitled');
  });

  it('cleans and compares maps', () => {
    expect(cleanLocalizedText({ ar: ' جامعة ', en: '  ' })).toEqual({ ar: 'جامعة' });
    expect(sameLocalizedText({ ar: 'x', en: '' }, { ar: 'x' })).toBe(true);
    expect(sameLocalizedText({ ar: 'x' }, { ar: 'y' })).toBe(false);
  });

  it('guesses Arabic from Arabic letters', () => {
    expect(guessLanguage('جامعة')).toBe('ar');
    expect(guessLanguage('Example Bakery')).toBe('en');
    expect(BILINGUAL).toEqual(['ar', 'en']);
  });
});
