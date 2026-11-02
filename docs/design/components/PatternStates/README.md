# Page states

Every list and record page uses these six states (V.2). A screen's README names its own wording; the shapes stay the same.

- **Loading:** the real layout with skeleton rows (five rows, widths varied), never a spinner or the word "Loading…". Page header and filters render at once.
- **Empty, first time:** what this place is for, in one sentence, and the one action that fills it. The action is the page's main button.
- **Empty after a filter:** names the search or filter, and offers Clear filters. Never the first-time message.
- **Error:** what failed in plain words and Try again, inside the area that failed; the rest of the page stays usable.
- **Read-only:** an info alert at the top naming the permission needed and who can grant it; fields render disabled, actions hidden, not greyed.
- **Access denied:** in place of the page content (the shell stays): what's behind it, the permission, who can give it, and Ask for access, which sends a request to them. Never a blank page or a raw 403.
