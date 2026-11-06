- **A few PHP calls no longer fail when the file system answers `false`.** The admin
  stats endpoint, the migrations list and the OpenAPI generator each used the result
  of `glob()`, `scandir()` or `file()` without checking it, so an unreadable directory
  became a `TypeError` or a warning. They now treat it as empty (WHIT-908).
- **An expression with an empty `min` or `max` fails as an expression error.** The
  plugin SDK's `Expression::evaluate()` threw PHP's `ValueError` for `{"min": []}`
  instead of the `ExpressionError` it documents. Validated expressions were never
  affected, because validation already rejects it (WHIT-908).
