/**
 * Plain words for codes people were being shown raw (docs/design A.3): an
 * audit action, a role's technical name. The code itself goes in
 * `TechnicalDetails` beside the words.
 */

/** "auth.login.success" -> "Auth login success"; "hello_viewer" -> "Hello viewer". */
export function humanizeCode(code: string): string {
  const words = code.replace(/[._:-]+/g, ' ').replace(/\s+/g, ' ').trim();
  return words === '' ? code : words.charAt(0).toUpperCase() + words.slice(1);
}
