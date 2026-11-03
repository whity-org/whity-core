'use client';

/**
 * The password rules, ticked as the person types (docs/design/components/
 * TplAuthReset). The rules are the ones the server enforces
 * (PasswordPolicy: 8 to 72 characters), plus the confirmation matching.
 */

import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';

const MIN = 8;
const MAX = 72;

export function passwordMeetsPolicy(password: string): boolean {
  return password.length >= MIN && password.length <= MAX;
}

export function PasswordChecklist({ password, confirm }: { password: string; confirm?: string }) {
  const t = useTranslation('auth');
  const rules: Array<{ ok: boolean; label: string }> = [
    {
      ok: password.length >= MIN,
      label: t('password.rule.min', 'At least {count} characters', { count: MIN }),
    },
    {
      ok: password.length > 0 && password.length <= MAX,
      label: t('password.rule.max', 'No more than {count} characters', { count: MAX }),
    },
  ];
  if (confirm !== undefined) {
    rules.push({
      ok: password.length > 0 && password === confirm,
      label: t('password.rule.match', 'Both entries are the same'),
    });
  }
  return (
    <ul className="space-y-1" aria-label={t('password.rule.heading', 'Password rules')}>
      {rules.map((rule) => (
        <li
          key={rule.label}
          className={`flex items-center gap-2 text-sm ${rule.ok ? 'text-success' : 'text-muted-foreground'}`}
        >
          <Icon name={rule.ok ? 'check' : 'close'} size={16} />
          <span>{rule.label}</span>
          <span className="sr-only">{rule.ok ? t('password.rule.met', 'met') : t('password.rule.unmet', 'not met yet')}</span>
        </li>
      ))}
    </ul>
  );
}
