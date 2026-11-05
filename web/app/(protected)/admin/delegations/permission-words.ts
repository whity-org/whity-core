/** "users:read" read as words ("Users read"); the code itself is a technical detail. */
export function permissionInWords(code: string): string {
  const words = code.replace(/[:_.-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A permission's description if it is one. Plugins that write none are given
 * "HelloWorld plugin permission (hello:view)", which only repeats the code.
 */
export function permissionText(code: string, description?: string | null): string {
  return description && !description.includes(code) ? description : permissionInWords(code);
}
