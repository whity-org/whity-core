'use client';

import { useState } from 'react';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { roleLabel } from '@whity/features/roles';
import { useTranslation } from '@whity/features/i18n';

interface BulkRoleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  count: number;
  roles: { id: number; name: string }[];
  onConfirm: (roleId: number) => void | Promise<void>;
}

/**
 * Change role for several people. Hard to undo in one step, so it asks and says how
 * many it reaches (tier 2).
 */
export function BulkRoleDialog({ open, onOpenChange, count, roles, onConfirm }: BulkRoleDialogProps) {
  const t = useTranslation('admin');
  const [roleId, setRoleId] = useState('');

  return (
    <ConfirmAction
      open={open}
      onOpenChange={(next) => {
        if (!next) setRoleId('');
        onOpenChange(next);
      }}
      variant="default"
      title={t('users.bulk.role.title', 'Change the role of {count} people?', { count })}
      description={
        <span className="block space-y-3">
          <span className="block">
            {t(
              'users.bulk.role.description',
              'They lose what their current role gives them straight away. If any of them cannot be changed, nobody is.'
            )}
          </span>
          <Select value={roleId} onValueChange={setRoleId}>
            <SelectTrigger aria-label={t('users.bulk.role.label', 'New role')} data-testid="users-bulk-role-select">
              <SelectValue placeholder={t('users.bulk.role.placeholder', 'Choose a role')} />
            </SelectTrigger>
            <SelectContent>
              {roles.map((role) => (
                <SelectItem key={role.id} value={String(role.id)}>
                  {roleLabel(role.name)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </span>
      }
      confirmLabel={t('users.bulk.role.confirm', 'Change role')}
      cancelLabel={t('users.bulk.cancel', 'Cancel')}
      onConfirm={() => {
        if (roleId !== '') return onConfirm(Number(roleId));
      }}
    />
  );
}
