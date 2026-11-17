---
"@whity/features": patch
---

`LanguageProvider` applies each translation bundle as it arrives (WHIT-1205).
A domain registered while bundles were loading used to drop the bundles already
fetched, so those screens stayed in the default language until a reload.
