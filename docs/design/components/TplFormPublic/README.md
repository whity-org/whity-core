# Public form

What someone outside the workspace sees at `/f/[slug]` (F.3).

- **The workspace's brand leads:** its logo and name above the card, its colour on the card's top edge, the main button, focus rings and selections (`tenantTheme`; here the demo tenant's orange `#b74c05`). Light and dark follow the visitor's system.
- **Footer:** a small **Built on Whity** (the `BuiltOnWhity` mark, mono), Privacy, and the language switch. No sign-in, no app shell.
- **Fields** are Whity components: phone with the country code, choices as radio buttons (no native dropdowns), file upload with progress and the file once uploaded, a consent checkbox when the form collects contact details.
- Errors appear under each field on Send, and the first one is focused. The button says what happens ("Send application"), not "Submit".
- All text is translatable; the form author writes the questions in each language they offer.

States: `TplFormPublicStates`.
