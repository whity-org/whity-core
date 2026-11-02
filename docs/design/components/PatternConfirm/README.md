# Confirming

Risky actions are confirmed by how undoable they are (P.4), so people aren't trained to click through dialogs.

1. **Undoable:** the action happens at once and a toast offers **Undo** for 10 seconds. The record keeps the reverse action (Reactivate, Restore). Examples: deactivate a user, retire a promotion, archive, hide a home card.
2. **Hard to undo:** a dialog whose title is the question ("Delete the Branch manager role?") and whose text names the consequence for people and data. Buttons: Cancel (ghost) and the verb (destructive). Examples: delete a role, unit or group; reject a sign-up; revoke an AI credential; uninstall a plugin.
3. **Security-sensitive or irreversible:** the same dialog, plus typing the email or name; the button stays disabled until it matches. Examples: clear two-step, approve a password reset or account recovery, delete a workspace.

The confirm button always repeats the verb ("Delete role", "Clear two-step"), never "OK" or "Yes". Every tier-2 and tier-3 action is logged with who did it. Fixes the inventory's unconfirmed actions: approving two-step resets and password resets (tier 3), rejecting sign-ups (tier 2), retiring a promotion and deactivating a user (tier 1).
