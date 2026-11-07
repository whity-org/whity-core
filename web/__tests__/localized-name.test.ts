import { cleanNames, guessNameLanguage, localizedName, sameNames } from '@/lib/localized-name';

describe('localizedName (#821)', () => {
  const names = { ar: 'جامعة عمان الأهلية', en: 'Al-Ahliyya Amman University' };

  it('shows the reader their language', () => {
    expect(localizedName(names, 'en', 'ar')).toBe('Al-Ahliyya Amman University');
    expect(localizedName(names, 'ar', 'ar')).toBe('جامعة عمان الأهلية');
  });

  it('falls back to the original, not to English', () => {
    expect(localizedName({ ar: 'جامعة' }, 'en', 'ar')).toBe('جامعة');
    expect(localizedName(names, 'fr', 'ar')).toBe('جامعة عمان الأهلية');
  });

  it('uses the fallback when there is no map', () => {
    expect(localizedName(undefined, 'ar', 'en', 'Example Bakery')).toBe('Example Bakery');
  });

  it('cleans and compares maps', () => {
    expect(cleanNames({ ar: ' جامعة ', en: '  ' })).toEqual({ ar: 'جامعة' });
    expect(sameNames({ ar: 'x', en: '' }, { ar: 'x' })).toBe(true);
    expect(sameNames({ ar: 'x' }, { ar: 'y' })).toBe(false);
  });

  it('guesses Arabic from Arabic letters', () => {
    expect(guessNameLanguage('جامعة')).toBe('ar');
    expect(guessNameLanguage('Example Bakery')).toBe('en');
  });
});
