---
"@whity/features": patch
---

Mark the root entry `@whity/features` and `@whity/features/expressions` as internal and deprecated for outside use: nothing imports them. Import a subpath such as `@whity/features/nav` instead. Both stay exported until the next major, which removes them.
