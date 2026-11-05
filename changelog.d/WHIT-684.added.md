- **Documents have a number, the record they came from, and a state.** A template or a
  plugin's slot can number its documents (`INV-{YYYY}-{SEQ:4}`), when issued or on final
  approval. The number prints, and `q` finds it. The API also returns the record a document
  was issued from, filterable with `?record=`, `?source_id=` and `?slot=`, a state derived
  from the routing trail (`draft`, `in_review`, `issued`, `superseded`, `withdrawn`), and the
  page count of each PDF. Older documents keep working with no number or record (WHIT-684,
  #1624, SDK 1.65).
