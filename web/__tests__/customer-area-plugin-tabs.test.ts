/**
 * Plugin screens in the customer area (SDK 1.174, WHIT-1424): the phone bar
 * keeps five slots (Home, three tabs, More), and a screen's token goes only to
 * its plugin's customer routes.
 */
import { phoneTabs } from '@/components/customer-area/customer-shell';
import { pathMatcher } from '@/lib/customer-screen';

const tab = (id: string) => ({ id: id as never, label: id, icon: 'dashboard' as const, href: `/@example-bakery/${id}`, count: id === 'x:grades' ? 2 : 0 });

describe('the phone bar', () => {
  it('shows every tab while five fit', () => {
    const tabs = ['home', 'bookings', 'bills', 'messages', 'account'].map(tab);
    expect(phoneTabs(tabs, 'More', 'example-bakery').map((t) => t.id)).toEqual(['home', 'bookings', 'bills', 'messages', 'account']);
  });

  it('puts the rest under More past five, with their counts', () => {
    const tabs = ['home', 'x:courses', 'x:grades', 'bookings', 'bills', 'messages', 'account'].map(tab);
    const bar = phoneTabs(tabs, 'More', 'example-bakery');
    expect(bar.map((t) => t.id)).toEqual(['home', 'x:courses', 'x:grades', 'bookings', 'more']);
    expect(bar[4]).toMatchObject({ href: '/@example-bakery/more', count: 0 });
  });
});

describe('where a screen token goes', () => {
  const matches = pathMatcher(['/api/v1/courses/mine', '/api/v1/courses/mine/{id}']);

  it('matches the plugin customer routes, versioned or not', () => {
    expect(matches('/api/v1/courses/mine')).toBe(true);
    expect(matches('/api/courses/mine/72')).toBe(true);
  });

  it('never matches core routes or the plugin staff routes', () => {
    expect(matches('/api/v1/customer-area/example-bakery/home')).toBe(false);
    expect(matches('/api/v1/courses/admin')).toBe(false);
    expect(matches('/api/v1/courses/mine/72/grades')).toBe(false);
  });
});
