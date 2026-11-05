/**
 * A role's name as a person reads it. Plugin roles carry machine names such as
 * `hello_viewer`; underscores become spaces and the first letter is capitalised
 * ("Hello viewer"). Names without an underscore are shown as the person typed
 * them, so "admin" and "Branch manager" are untouched.
 */
export function roleLabel(name: string): string {
  if (!name.includes('_')) return name;
  const words = name.replace(/_+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}
