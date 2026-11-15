/**
 * Link previews (design 3.4 `SiteShareCards`, WHIT-1041): the text rules the
 * renderer depends on. The pictures themselves were checked by eye.
 */
import { clip, forDisplay, initials, inkOn, isArabic } from '@/lib/share-cards/card';

jest.mock('next/og', () => ({ ImageResponse: class {} }));

describe('share card text', () => {
  it('tells Arabic content from Latin', () => {
    expect(isArabic('مخبز المثال')).toBe(true);
    expect(isArabic('Example Bakery')).toBe(false);
    expect(isArabic('Book a time · مخبز')).toBe(false);
  });

  it('clips on a word boundary with an ellipsis', () => {
    expect(clip('Book your first visit', 100)).toBe('Book your first visit');
    expect(clip('Ethics approval for the long study of bread', 20)).toBe('Ethics approval for…');
  });

  it('gives the renderer Arabic words in display order, short enough not to wrap', () => {
    expect(forDisplay('انضم إلى مخبز', true, 28)).toBe('مخبز إلى انضم');
    expect(forDisplay('Join Example Bakery', false, 28)).toBe('Join Example Bakery');
  });

  it('writes white on a dark colour and near-black on a light one', () => {
    expect(inkOn('#0961bb')).toBe('#ffffff');
    expect(inkOn('#f5d000')).toBe('#141414');
    expect(inkOn('not a colour')).toBe('#ffffff');
  });

  it('makes two initials for a workspace with no logo', () => {
    expect(initials('Example Bakery')).toBe('EB');
    expect(initials('default')).toBe('D');
  });
});
