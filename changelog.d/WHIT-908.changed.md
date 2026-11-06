- **Static analysis runs on PHPStan 2.** The PHP code is now checked with
  PHPStan 2.3 at level 8, which also reports conditions that are always true and
  enforces `list` types. A cold analysis takes about a fifth of the time it did and
  each worker uses about a third of the memory, so CI is back to the 1G limit the
  `composer phpstan` script has always used. Every new finding was fixed rather than
  added to the baseline, and the baseline lost 38 entries that no longer apply
  (WHIT-908).
