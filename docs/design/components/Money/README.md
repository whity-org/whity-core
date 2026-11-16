# Money

An amount in the workspace's currency, in tabular figures. US dollars (the default in designs) show the symbol and 2 decimals: $312.00. Currencies without a symbol show their code and their own decimals: JOD 312.000, SAR 312.00.

**Props:** `amount`, `currency` (ISO code, default USD; symbol and decimals come from `Whity.currencies`), `decimals`. Negative amounts are `destructive`. Western digits in every language. Hand-written from `packages/ui/src/money`.
