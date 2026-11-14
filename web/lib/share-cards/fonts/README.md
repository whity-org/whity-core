# Link preview fonts

The brand's own fonts from `docs/design/fonts/`, converted from woff2 to TTF
because the image renderer behind `next/og` reads no woff2 (WHIT-1041). Noto
Sans and Readex Pro are under the SIL Open Font License 1.1.

Arabic uses Readex Pro only: the renderer shapes its Arabic but not Noto Sans
Arabic's, whose contextual substitutions it can't read. To rebuild a file,
load the woff2 with fontTools, set `flavor = None` and save as `.ttf`.
