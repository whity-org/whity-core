'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useTranslation } from '@whity/features/i18n';
import type { Tenant } from './page';

interface DeleteTenantModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: Tenant;
  onSuccess: () => void;
}

export function DeleteTenantModal({
  isOpen,
  onOpenChange,
  tenant,
  onSuccess,
}: DeleteTenantModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const response = await apiClient(`/api/v1/tenants/${tenant.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || t('tenants.delete.error', 'Failed to delete tenant')
        );
      }

      addToast(t('tenants.delete.success', 'Tenant deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('tenants.delete.error', 'Failed to delete tenant');
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  // Tier 3: it cannot be undone, so the workspace's name has to be typed.
  return (
    <ConfirmAction
      open={isOpen}
      onOpenChange={onOpenChange}
      title={t('tenants.delete.confirmTitle', 'Delete {name}?', { name: tenant.name })}
      description={
        <>
          <span className="block">
            {tenant.userCount === 0
              ? t('tenants.delete.consequenceEmpty', 'The workspace and its data will be removed. This cannot be undone.')
              : tenant.userCount === 1
                ? t(
                    'tenants.delete.userCount.one',
                    'This tenant has 1 associated user. Deleting it may impact those users.'
                  )
                : t(
                    'tenants.delete.userCount.other',
                    'This tenant has {count} associated users. Deleting it may impact those users.',
                    { count: tenant.userCount }
                  )}
          </span>
          {tenant.userCount > 0 && (
            <span className="mt-2 block">
              {t('tenants.delete.consequence', 'The workspace and its data will be removed. This cannot be undone.')}
            </span>
          )}
        </>
      }
      confirmLabel={isDeleting ? t('tenants.delete.submitting', 'Deleting...') : t('tenants.delete.submit', 'Delete Tenant')}
      cancelLabel={t('tenants.delete.cancel', 'Cancel')}
      typeToConfirm={{
        value: tenant.name,
        label: t('tenants.delete.typeName', 'Type {name} to confirm', { name: tenant.name }),
      }}
      onConfirm={handleDelete}
    />
  );
}
