/**
 * WHIT-1069: reading ⌘K's query as a command, and matching names the way
 * server search folds Arabic.
 */

import { fold, nameScore, parseCommand, rank } from '@/lib/commands/parse';

describe('parseCommand', () => {
  it('is not a command without a leading slash', () => {
    expect(parseCommand('new task')).toBeNull();
    expect(parseCommand('')).toBeNull();
  });

  it('reads the name and, after a space, the rest', () => {
    expect(parseCommand('/')).toEqual({ name: '', named: false, rest: '' });
    expect(parseCommand('/ne')).toEqual({ name: 'ne', named: false, rest: '' });
    expect(parseCommand('/new ')).toEqual({ name: 'new', named: true, rest: '' });
    expect(parseCommand('  /new  task Order flour')).toEqual({ name: 'new', named: true, rest: 'task Order flour' });
  });
});

describe('fold', () => {
  it('folds Arabic letter forms, marks and digits', () => {
    expect(fold('مُهِمَّة')).toBe(fold('مهمه'));
    expect(fold('إنشاء')).toBe('انشاء');
    expect(fold('مستوى')).toBe('مستوي');
    expect(fold('٤٢')).toBe('42');
    expect(fold(' New ')).toBe('new');
  });
});

describe('rank', () => {
  const items = [
    { id: 'settings', names: ['Settings'] },
    { id: 'users', names: ['Users', 'people'] },
    { id: 'user-groups', names: ['User groups'] },
  ];

  it('puts exact, then starts-with, then contains', () => {
    expect(nameScore('users', ['Users'])).toBe(3);
    expect(nameScore('use', ['Users'])).toBe(2);
    expect(nameScore('ser', ['Users'])).toBe(1);
    expect(nameScore('xyz', ['Users'])).toBe(0);
    expect(rank('users', items, (i) => i.names).map((i) => i.id)).toEqual(['users']);
    expect(rank('user', items, (i) => i.names).map((i) => i.id)).toEqual(['users', 'user-groups']);
  });

  it('keeps everything, in order, for an empty query', () => {
    expect(rank('', items, (i) => i.names).map((i) => i.id)).toEqual(['settings', 'users', 'user-groups']);
  });
});
