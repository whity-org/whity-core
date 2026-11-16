/**
 * WHIT-1070: what ⌘K remembers between openings, per person and workspace.
 */

import { MAX_COMMANDS, MAX_PLACES, readRecent, rememberCommand, rememberPlace } from '@/lib/commands/recent';

beforeEach(() => window.localStorage.clear());

describe('recent', () => {
  it('keeps the newest first, once each, up to the limit', () => {
    for (let i = 1; i <= MAX_PLACES + 2; i++) rememberPlace('1:1', { id: `p${i}`, label: `Page ${i}`, href: `/p/${i}` });
    rememberPlace('1:1', { id: 'p3', label: 'Page 3', href: '/p/3' });
    const places = readRecent('1:1').places.map((p) => p.id);
    expect(places).toHaveLength(MAX_PLACES);
    expect(places[0]).toBe('p3');
    expect(new Set(places).size).toBe(places.length);

    for (const line of ['/theme', '/go', '/new task', '/lang', '/theme']) rememberCommand('1:1', line);
    expect(readRecent('1:1').commands).toEqual(['/theme', '/lang', '/new task'].slice(0, MAX_COMMANDS));
  });

  it('is separate per person and workspace', () => {
    rememberPlace('1:1', { id: 'a', label: 'A', href: '/a' });
    expect(readRecent('1:2').places).toEqual([]);
  });

  it('drops anything that would leave the site, or is not a command', () => {
    window.localStorage.setItem(
      'whity:palette-recent:1:1',
      JSON.stringify({
        places: [
          { id: 'x', label: 'X', href: 'https://example.com' },
          { id: 'y', label: 'Y', href: '//example.com' },
          { id: 'z', label: 'Z', href: '/admin/users' },
        ],
        commands: ['rm -rf', '/go'],
      }),
    );
    expect(readRecent('1:1')).toEqual({ places: [{ id: 'z', label: 'Z', href: '/admin/users' }], commands: ['/go'] });
  });

  it('survives storage that is broken or off', () => {
    window.localStorage.setItem('whity:palette-recent:1:1', '{not json');
    expect(readRecent('1:1')).toEqual({ places: [], commands: [] });
    const spy = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError');
    });
    expect(() => rememberCommand('1:1', '/go')).not.toThrow();
    spy.mockRestore();
  });
});
