# Mobile onboarding: account and workspace

After the story, for people who choose email (Apple and Google skip the first two screens). A thin progress bar at the top shows the five steps (step 3, your picture, is `TmOnboardPicture`); the main button is always at the bottom.

1. **Your details:** name, email and password (12 characters at least), in 52px fields.
2. **Check your email:** a 6-digit code in six boxes; it fills in by itself from the email or a text where the phone allows, with a countdown to send a new one.
3. **Protect your account** (M.33): **Use this phone** (a passkey; Face ID signs in and approves sign-ins elsewhere, M.32) is the default and the main button; **An authenticator app**; or **Later**, unless a workspace requires two-step sign-in.
4. **Your workspace** (M.29): invitations waiting for that email come first, with Join; then **Create a workspace** (tenant creators, `TmCreatorCustomer`) or **Join with a code or QR**.
