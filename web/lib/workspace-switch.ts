/**
 * Why a workspace switch was refused, in plain words (2026-11-22).
 *
 * The switcher must never fail silently: whatever the server answers, the
 * person is told why they are still where they were, in English or Arabic.
 * Server texts are never shown as they are; they are English-only and written
 * for developers ("Access to the requested tenant is forbidden").
 */
import { useCallback } from 'react';
import { useTranslation } from '@whity/features/i18n';

/** The server's refusal for a session confined to its own workspace (#2249). */
export const CONFINED_REFUSAL = 'This sign-in only works inside the workspace that provided it';

/** A refused `POST /api/v1/auth/switch-tenant`. Status 0: it never reached the server. */
export class WorkspaceSwitchRefused extends Error {
  readonly status: number;
  readonly serverMessage: string | null;

  constructor(status: number, serverMessage: string | null) {
    super(serverMessage ?? `Workspace switch refused (${status})`);
    this.name = 'WorkspaceSwitchRefused';
    this.status = status;
    this.serverMessage = serverMessage;
  }
}

export type SwitchRefusalReason =
  | 'signed_out'
  | 'confined'
  | 'not_member'
  | 'paused'
  | 'too_many'
  | 'unreachable'
  | 'other';

export function switchRefusalReason(err: unknown): SwitchRefusalReason {
  if (!(err instanceof WorkspaceSwitchRefused)) return 'other';
  switch (err.status) {
    case 0:
      return 'unreachable';
    case 401:
      return 'signed_out';
    case 402:
      return 'paused';
    case 403:
      return err.serverMessage === CONFINED_REFUSAL ? 'confined' : 'not_member';
    case 429:
      return 'too_many';
    default:
      return err.status >= 500 ? 'unreachable' : 'other';
  }
}

/** The sentence a refused switch shows, in the reader's language. */
export function useSwitchRefusalMessage(): (err: unknown) => string {
  const t = useTranslation('common');

  return useCallback((err: unknown): string => {
      switch (switchRefusalReason(err)) {
        case 'signed_out':
          return t('workspaceSwitch.refused.signedOut', 'Your sign-in has ended. Sign in again, then open the workspace.');
        case 'confined':
          return t(
            'workspaceSwitch.refused.confined',
            'This sign-in only works in the workspace you signed in to. Sign in with Whity ID to open your other workspaces.',
          );
        case 'not_member':
          return t(
            'workspaceSwitch.refused.notMember',
            'You’re no longer a member of that workspace. Ask one of its owners to add you again.',
          );
        case 'paused':
          return t('workspaceSwitch.refused.paused', 'That workspace is paused until an owner pays.');
        case 'too_many':
          return t('workspaceSwitch.refused.tooMany', 'Too many tries in a row. Wait a minute, then try again.');
        case 'unreachable':
          return t('workspaceSwitch.refused.unreachable', 'Whity couldn’t be reached. Check your connection, then try again.');
        default:
          return t('workspaceSwitch.refused.other', 'That workspace couldn’t be opened. Try again.');
      }
  }, [t]);
}
