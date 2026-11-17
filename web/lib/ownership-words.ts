'use client';

import { useTranslation } from '@whity/features/i18n';

/** Plain words for why an ownership change was refused (WHIT-1170), by the server's code. */
export function useRefusalText(): (code: string | undefined, fallback: string) => string {
  const t = useTranslation('admin');
  return (code, fallback) => {
    switch (code) {
      case 'not_owner':
        return t('owners.error.notOwner', 'Only an owner can do this.');
      case 'not_member':
        return t('owners.error.notMember', 'That person isn’t an active member of this workspace.');
      case 'already_owner':
        return t('owners.error.alreadyOwner', 'That person already owns this workspace.');
      case 'offer_pending':
        return t('owners.error.pending', 'That person already has an offer waiting.');
      case 'not_open':
        return t('owners.error.notOpen', 'That offer is no longer open.');
      case 'expired':
        return t('owners.error.expired', 'That offer has expired.');
      case 'last_owner':
        return t('owners.error.lastOwner', 'A workspace always keeps at least one owner.');
      default:
        return fallback;
    }
  };
}
