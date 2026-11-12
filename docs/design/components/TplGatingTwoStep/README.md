# Approval gating: two admins for two-step resets

Every gating approval (sign-ups, password resets, two-step resets) asks for **Face ID or a passkey**; **clearing someone's two-step needs a second admin** too (AG.1).

- **Tabs** by kind with counts; requests show who, why and when, with the request date (missing in the built app).
- **The request:** what approving does, in plain words; where it came from; the account; when they last signed in.
- **Checks:** what the first admin did to confirm it's really them (a call to the number on file, the manager's confirmation), with who and when.
- **Two admins approve:** the first approval, with how it was verified, and the second slot waiting. The second admin must be a different person; the requester can never approve their own.
- **There's no Undo**, said once in a warning; **Approve with your passkey** (or Face ID on the phone). Rejecting sends a kind message and logs it.
