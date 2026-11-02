# Install a plugin

One dialog, up to four steps, with a Stepper on top. A step that doesn't apply is left out (Plan for free plugins, Set up when the plugin declares no settings), and the Stepper counts only the steps shown.

1. **Access.** Plain sentences for what the plugin can do, with the permission codes underneath for admins who want them: the records it reads and changes, the screens and blocks it adds, the agent tools it adds and their starting mode (Ask first, S.5; on the tint like everything agents do), and, in a warning row, every outside domain it sends data to and what goes there. "Allow and continue" is the consent; it is logged with the person and the permission list.
2. **Plan.** Monthly or yearly as cards, prices `[price]` until decided. If the publisher offers a trial (S.8), the trial box says when billing starts and that a reminder comes before; the main button becomes "Start free trial". Everything is billed on the Whity invoice (S.2).
3. **Set up.** Settings the plugin declares in its manifest, drawn with Whity form components. "Skip for now" is allowed; the plugin then shows a "Finish setting up" banner on its own screens.
4. **Done.** Where each added thing lives, with links, and the trial end date. Main button: open the plugin's first screen. Verified and By Whity plugins are active from here (S.12). A sandboxed plugin installs turned off: Done says so, and its main button is **Turn on**, which shows the Access list once more.

People without `plugins:install` (S.3, S.9) never reach this dialog: the detail page's Install is disabled with "Ask someone with permission to install plugins", plus "Ask to install", which sends the request to the people who can. An agent asking to install becomes a proposal like any other action.
