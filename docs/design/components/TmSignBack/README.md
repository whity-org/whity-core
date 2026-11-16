# Mobile: signing back in

- **Unlocking** (M.8): after the app-lock time, Whity opens on the workspace's logo and asks for Face ID (or fingerprint) straight away. **Use my app PIN** is the fallback.
- **App PIN:** six dots, an 80px keypad, Face ID to the bottom left and delete to the bottom right. After 5 wrong tries the phone signs out of Whity (the account is safe; signing in again needs the Whity ID).
- **A new phone** (M.26, M.32): type the Whity ID email; the other phone gets "Is this you signing in?" and the person taps the number shown here (**42**), then Face ID. **Use a passkey instead** works with any synced passkey.
- **Lost phone:** a recovery code (`TmSecuritySetup`), after which this phone becomes the passkey and the lost one is signed out; or ask the workspace admin to reset two-step (tier 3 for them).
