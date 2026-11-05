'use client';

import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useTranslation } from '@whity/features/i18n';
import type { AiPrincipal } from './types';

interface RevokeAiPrincipalModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  principal: AiPrincipal;
  /** The caller's own connection: the route anyone signed in may use, not the admin one. */
  own?: boolean;
  onSuccess: () => void;
}

export function RevokeAiPrincipalModal({
  isOpen,
  onOpenChange,
  principal,
  own = false,
  onSuccess,
}: RevokeAiPrincipalModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const handleRevoke = async () => {
    try {
      const response = await apiClient(
        `/api/v1/${own ? '' : 'admin/'}mcp/tokens/${principal.jti}`,
        { method: 'DELETE' }
      );

      if (!response.ok) {
        if (response.status === 404) {
          addToast(
            t(
              'aiPrincipals.revoke.notFound',
              'Token not found — it may have already been revoked.'
            ),
            'error'
          );
          onSuccess();
          return;
        }
        const errorData = await response.json().catch(() => ({}));
        const errorObj = errorData as { message?: string };
        throw new Error(
          errorObj.message ?? t('aiPrincipals.revoke.error', 'Failed to revoke token')
        );
      }

      addToast(
        t('aiPrincipals.revoke.success', 'Token "{name}" revoked', { name: principal.name }),
        'success'
      );
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('aiPrincipals.revoke.error', 'Failed to revoke token');
      addToast(message, 'error');
    }
  };

  return (
    <ConfirmAction
      open={isOpen}
      onOpenChange={onOpenChange}
      title={t('aiPrincipals.revoke.title', 'Disconnect {name}?', { name: principal.name })}
      description={t(
        'aiPrincipals.revoke.description',
        '{name} stops working right away. To use it again, connect it again.',
        { name: principal.name }
      )}
      confirmLabel={t('aiPrincipals.revoke.submit', 'Disconnect app')}
      cancelLabel={t('aiPrincipals.revoke.cancel', 'Keep connected')}
      onConfirm={handleRevoke}
    />
  );
}
