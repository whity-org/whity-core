# Mobile: connect and theme

One Whity app serves every installation (M.13): it connects to the installation a person works in, takes its design tokens, and looks like that company's own app.

1. **Before connecting** the app is plain Whity: the mark, "Sign in to your workspace", a work email field and Continue in the thumb zone, with **Scan a QR code** (from the web app's account page) and **Type an address** as alternatives (M.14).
2. **Found by email:** the installation and workspace the email belongs to, with its logo, address and a preview of its colours. **Continue to Example Bakery**, or try another email.
3. **Connected:** the whole app takes the installation's tokens (M.15): colours, logo, name, type, corners, light and dark; Whity defaults fill any gap. Guard rails: 17px text and 48px targets always hold, and any colour pair that fails contrast falls back to Whity's. The theme is cached for offline use and refreshed when the installation publishes a new version.
4. **Switching** (M.17): hold the Home tab, or tap the workspace logo, to open **Your workspaces**, each with its logo, colour, installation address and role. Switching re-themes the app. Add a workspace runs step 1 again.

Store identity (M.16): the free app's icon and name stay Whity; an installation can pay for its own store app, built from the same code with its icon and name.

For the Whity team: each installation publishes its theme as tokens (the same shape as `tokens.json`) at a well-known address, with a version so the app knows when to refresh.
