/** "users:read" read as words ("Users read"); the code itself is a technical detail. */
export function permissionInWords(code: string): string {
  const words = code.replace(/[:_.-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}
