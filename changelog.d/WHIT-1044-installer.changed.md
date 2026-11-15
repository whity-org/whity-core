- **The installer's output follows its 3.4 card.** `install.sh` now opens with
  "Whity Core installer · free and open source (AGPL-3.0)", prints each check
  and step as one ✓ line with its detail beside it, stops with ✗, one line on
  what to do and "Nothing was changed", warns with ! when the machine has less
  than 2 GB of memory, and ends with "Whity is ready." and where to open it.
  It speaks Arabic when the system language is Arabic, keeps commands and
  addresses in Latin, and `--verbose` shows each command's own output (WHIT-1044).
