# Inventory

Stock in every place, plus recipes and batches (IN.1).

- **Tabs:** Stock, Batches, Recipes, Counts, Transfers. Actions: **Count stock** (the phone's scanner count, `TmFormsMobile`), **Transfer** between places, **Make a batch**.
- **Stock:** one row per item with how many are in each place, the reorder level, and a badge when it matters (low in one place, a lot expiring). Items you make carry the recipe icon. Numbers are mono; a low figure is red.
- **The Stock assistant** watches the pace of use and proposes reorders as purchase orders, grouped by supplier, with why ("Sweifieh runs out of flour on Wednesday"). Approving sends them (or they wait in Approvals if its scope says Ask first).
- **Expiring soon:** batches and lots by expiry date, the ones within 7 days first, so they're sold or used first (first expiring, first out).
