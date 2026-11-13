- **Test databases match a real migration run.** On SQLite the tests' `NOW()`
  read the clock on every call, so about one migration run in thirty under load
  wrote a translation whose `updated_at` was a second after its `created_at`,
  and migration 124 left that row unclaimed. The tests could then pass against
  data no real install has. `NOW()` now holds still for a whole statement, as
  on PostgreSQL. The cached test template is also keyed on the translation
  catalogues, the source files of classes the migrations call, and the
  rendering settings migration 238 reads (WHIT-949).
