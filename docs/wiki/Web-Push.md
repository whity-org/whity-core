# Web push notifications

People can turn on notifications for each device they use Whity on: a desktop browser, an Android phone, or an iPhone or iPad with Whity added to the Home Screen. A notification comes in the workspace's name, in the person's language, and opens the record when tapped.

## What a person sees

- **Asked after sign-in, answered once.** Every signed-in person sees a card at the top of the app: "Get notified when someone messages you in chat?" The browser's own permission prompt appears only after **Turn on notifications** is tapped, never when a page loads. **Not now** asks again after 14 days; **Don't ask again**, or any answer to the browser's prompt, ends it in that browser. Public pages never show it.
- **Settings › Preferences › Notifications:** this device on or off, the person's other devices (each can be turned off), and which kinds come as a notification. Sign-in and security notifications always come.
- **iPhone and iPad:** Safari offers notifications only to a web app on the Home Screen (iOS 16.4 and later). In Safari the card is not shown and Preferences says to add Whity to the Home Screen first.
- **Signing out** stops the browser getting anyone's notifications, so a shared device is safe.

## Who gets one

A notification shown in Whity also goes as a push to every device the person turned on in that workspace, when:

1. the workspace has not turned push off (the `push` channel's `enabled`, `PUT /api/v1/notification-settings/push`), and
2. the person has not turned that kind (or every kind) off on push.

These are the same rules as email (`EmailGate`). Security and account notifications always go. Nothing is sent for anything that happened before a device was turned on.

**A burst can't flood a phone.** A person gets at most 4 pushes a minute and 30 an hour per workspace. The rest are held, and one "N more things are waiting for you" follows when the minute (or hour) is over. Security notifications are never held.

## Team chat

Each new line in a team chat is pushed to the chat's other people who have a device on, except the author and anyone looking at that chat right now (open in a focused window; the chat screen says so every 20 seconds). A chat's pushes share one tag, so the newest replaces the last on the device and counts what's unread: "3 new messages in #Front desk". Mentions are their own kind, "Amro Saleh mentioned you in #Front desk", so a person can keep mentions while turning ordinary chat messages off. Both kinds are on until turned off. An agent's line held for approval is pushed only once approved.

## Self-hosting

- **No account with any push provider.** The instance makes its own VAPID key pair (RFC 8292) the first time it is needed and keeps the private key encrypted with `ENCRYPTION_KEY` (app setting `push.vapid.keys`). Messages are encrypted to each device (RFC 8291).
- **Outbound HTTPS** from the server and the queue worker to the browsers' push services: `fcm.googleapis.com` (Chrome, Edge on Android, Samsung Internet), `*.push.services.mozilla.com` (Firefox), `*.push.apple.com` (Safari), `*.notify.windows.com` (Edge on Windows). Subscriptions to any other host are refused; add your own push service with `PUSH_SERVICE_HOSTS=host1,host2`.
- **Installing must be on** (`app.installable`, default on): push needs the app's service worker.
- **The queue worker** sends pushes, like email.
- **Changing the key** (deleting `push.vapid.keys`) ends every subscription; devices are removed as the push services refuse them, and people turn notifications on again.

## Themed per workspace

| Part | What Whity sets |
|---|---|
| Title | "Workspace · what's waiting", e.g. "AAU IRB · Application waiting for pre-check". The workspace's own site name when it set one, else its name. |
| Text | One short line, in the person's language (Arabic or English), right to left for Arabic. |
| Icon | The workspace's square logo; without one, its initial on its main colour. |
| Badge | The workspace's initial as a white silhouette (Android draws badges from transparency only, so a colour logo can't be used). |
| Tapping | Opens the record on the site the person uses (a workspace on its own domain opens its own domain). |

**What the operating system controls, not Whity:** the notification's colours, font, layout and size; the sound and vibration; how notifications are grouped and stacked (iPhone groups by app); how long one stays; the badge's tint on Android; whether text shows on the lock screen. Action buttons are deliberately not used: a notification only opens Whity.

**The installed app's name and icon** come from the web app manifest, which is drawn per host: a workspace on its own domain installs as its own app (its name, icon and colour). On a shared host such as app.whity.dev every workspace shares one site, so the installed app is the instance's; the notifications themselves still carry each workspace's name and icon.

## Troubleshooting

- Preferences says notifications are blocked: allow them for the site in the browser's settings.
- A device stops getting notifications: the push service ended the subscription (the device was reset, the browser data cleared); it is removed on the next push. Turn it on again on that device.
- Nothing arrives anywhere: check the queue worker is running and can reach the push service hosts above.
