# User record (A10)

The record pattern (P.2) with in-place editing (P.3).

- **Header:** avatar, name, status badge, key facts (role, unit, email, last active), Send reset link and Require two-step as buttons, ⋯ with Deactivate and Delete (A.2). No stat tiles: the facts line carries them.
- **Tabs:** Overview (details and at a glance), Access (roles per workspace, groups, delegations, effective permissions, the replacement for "Tenants and roles"), History (the audit trail in words: "Amro changed his unit from Sweifieh to Abdoun", never codes like `user.membership.removed`).
- **Editing (P.3):** fields are editable in place. A changed field gets the primary ring and a "Was …" note; the sticky save bar names the change and offers Discard and **Save changes**, the view's one main button. Leaving with unsaved changes asks first. Display name is editable here (the inventory found it locked in account settings).
- **His agents:** agents acting for this person, with their mode summary.
- **Read-only:** without `users:write`, the read-only alert (V.2) and no save bar.

Arabic card: `TplUserRecordAr`.
