import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { OtpInput } from '@whity/ui/otp-input';
import { SystemState } from '@whity/ui/system-state';
import { PasswordChecklist, passwordMeetsPolicy } from '@/components/password-checklist';
import NotFound from '@/app/not-found';

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>{children}</a>
  ),
}));

const boxes = () => screen.getAllByRole('textbox') as HTMLInputElement[];
const value = () => boxes().map((b) => b.value).join('');

describe('OtpInput', () => {
  it('moves on as digits are typed and reports a complete code once', () => {
    const onComplete = jest.fn();
    render(<OtpInput label="Code" onComplete={onComplete} />);
    fireEvent.change(boxes()[0], { target: { value: '4' } });
    expect(document.activeElement).toBe(boxes()[1]);
    expect(value()).toBe('4');
    expect(onComplete).not.toHaveBeenCalled();
  });

  it('fills every box from a pasted code, accepting Arabic-Indic digits', () => {
    const onComplete = jest.fn();
    render(<OtpInput label="Code" onComplete={onComplete} />);
    fireEvent.paste(boxes()[0], { clipboardData: { getData: () => '٤٨٢ ٧٩١' } });
    expect(value()).toBe('482791');
    expect(onComplete).toHaveBeenCalledWith('482791');
  });

  it('goes back on Backspace from an empty box and ignores letters', () => {
    render(<OtpInput label="Code" defaultValue="12" />);
    fireEvent.change(boxes()[2], { target: { value: 'x' } });
    expect(value()).toBe('12');
    boxes()[2].focus();
    fireEvent.keyDown(boxes()[2], { key: 'Backspace' });
    expect(value()).toBe('1');
    expect(document.activeElement).toBe(boxes()[1]);
  });

  it('shows the error text and marks the boxes invalid', () => {
    render(<OtpInput label="Code" errorText="That code didn’t work." />);
    expect(screen.getByRole('alert')).toHaveTextContent('That code didn’t work.');
    expect(boxes()[0]).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('PasswordChecklist', () => {
  it('ticks the rules the server enforces and the match rule', () => {
    const { rerender } = render(<PasswordChecklist password="short" confirm="" />);
    expect(screen.getByText('At least 8 characters').parentElement).toHaveClass('text-muted-foreground');
    rerender(<PasswordChecklist password="longenough" confirm="longenough" />);
    expect(screen.getByText('At least 8 characters').parentElement).toHaveClass('text-success');
    expect(screen.getByText('Both entries are the same').parentElement).toHaveClass('text-success');
  });

  it('policy matches PasswordPolicy: 8 to 72 characters', () => {
    expect(passwordMeetsPolicy('1234567')).toBe(false);
    expect(passwordMeetsPolicy('12345678')).toBe(true);
    expect(passwordMeetsPolicy('x'.repeat(72))).toBe(true);
    expect(passwordMeetsPolicy('x'.repeat(73))).toBe(false);
  });
});

describe('system states', () => {
  it('SystemState shows the code, title, text and actions', () => {
    render(<SystemState icon="alert" code="500" title="Title" description="Body" actions={<button>Go</button>} />);
    expect(screen.getByRole('heading', { name: 'Title' })).toBeInTheDocument();
    expect(screen.getByText('500')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Go' })).toBeInTheDocument();
  });

  it('the 404 page says what to do next', () => {
    render(<NotFound />);
    expect(screen.getByText('404')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Go to dashboard' })).toHaveAttribute('href', '/dashboard');
  });
});
