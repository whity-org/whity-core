/**
 * A refused "Confirm it's you" is told apart from any other refusal, whether
 * the server sends the reason under `details` (Response::error) or on top.
 */
import { isIdentityCheckFailure } from '@/components/security/confirm-its-you-dialog';

describe('isIdentityCheckFailure', () => {
  it('reads the reason under details, as the server sends it', () => {
    expect(isIdentityCheckFailure({ error: 'Confirm it is you first', details: { reason: 'identity_check_failed' } })).toBe(true);
  });

  it('still reads a reason on top', () => {
    expect(isIdentityCheckFailure({ reason: 'identity_check_failed' })).toBe(true);
  });

  it('is false for any other refusal', () => {
    expect(isIdentityCheckFailure({ error: 'Only an owner can do this', details: { code: 'not_owner' } })).toBe(false);
    expect(isIdentityCheckFailure(null)).toBe(false);
    expect(isIdentityCheckFailure('identity_check_failed')).toBe(false);
  });
});
