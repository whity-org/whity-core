# MoneyInput

For money: the currency as a fixed prefix, figures end-aligned in tabular digits, always left to right, formatted to the currency's decimals when you leave the field (1,234.50 for US dollars; 1,234.500 for Jordanian dinars). It accepts Arabic-Indic digits and the Arabic decimal mark as typed and turns them into Western digits (3.7b). More decimals than the currency has is an error that says why. `showWords` puts the amount in words under the field, in English or Arabic, the same words that print on documents, so a typo of a zero shows straight away.
