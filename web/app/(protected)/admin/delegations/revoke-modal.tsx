'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { permissionInWords } from './permission-words';
import type { Delegation } from './types';

interface RevokeDelegationModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  delegation: Delegation;
  /** Who it was delegated to, as a name. */
  granteeName: string;
  /** Where it applies, as a name. */
  scopeName: string;
  onSuccess: () => void;
}

/**
 * Revoke-delegation confirmation dialog (WC-34). Revocation is non-destructive
 * server-side (sets `revoked_at`); the grantee loses the delegated access
 * immediately.
 */
export function RevokeDelegationModal({
  isOpen,
  onOpenChange,
  delegation,
  granteeName,
  scopeName,
  onSuccess,
}: RevokeDelegationModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isRevoking, setIsRevoking] = useState(false);

  const handleRevoke = async () => {
    try {
      setIsRevoking(true);
      const { error, response } = await api.DELETE('/api/v1/delegations/{id}', {
        params: { path: { id: delegation.id } },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('delegations.revoke.error', 'Failed to revoke delegation')
        );
      }

      addToast(t('delegations.revoke.success', 'Delegation revoked successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('delegations.revoke.error', 'Failed to revoke delegation');
      addToast(message, 'error');
    } finally {
      setIsRevoking(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('delegations.revoke.title', 'Revoke this delegation?')}</DialogTitle>
          <DialogDescription>
            {t(
              'delegations.revoke.description',
              'They lose the delegated access straight away.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium">{permissionInWords(delegation.permission)}</div>
            <TechnicalDetails>
              <div className="font-mono text-xs text-muted-foreground">{delegation.permission}</div>
            </TechnicalDetails>
            <div className="mt-1 text-xs text-muted-foreground">
              {t('delegations.revoke.grantee', 'Delegated to {name} ({scope})', {
                name: granteeName,
                scope: scopeName,
              })}
            </div>
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              {t(
                'delegations.revoke.warning',
                "This can't be undone here. To give the access back, delegate it again."
              )}
            </AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isRevoking}
          >
            {t('delegations.revoke.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleRevoke}
            disabled={isRevoking}
          >
            {isRevoking
              ? t('delegations.revoke.submitting', 'Revoking…')
              : t('delegations.revoke.submit', 'Revoke delegation')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
