/**
 * A select is a control, so it is 40px, or 32px small (A.1-A.3). Two heights in one
 * class string once made the later, smaller pair win, and jsdom has no layout to
 * catch that by measuring, so this reads the classes.
 */

import { render, screen } from '@testing-library/react';
import { Select, SelectTrigger, SelectValue } from '@whity/ui/select';

const heights = (className: string) => className.split(/\s+/).filter((name) => /^data-\[size=[a-z]+\]:h-/.test(name));

const trigger = (size?: 'sm' | 'default') => {
  render(
    <Select>
      <SelectTrigger size={size} aria-label="Choice">
        <SelectValue />
      </SelectTrigger>
    </Select>
  );

  return screen.getByRole('combobox', { name: 'Choice' });
};

describe('SelectTrigger', () => {
  it('has one height per size: 40 and 32', () => {
    expect(heights(trigger().className)).toEqual(['data-[size=default]:h-10', 'data-[size=sm]:h-8']);
  });
});
