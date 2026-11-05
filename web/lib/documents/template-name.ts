/**
 * The readable part of a form-raised document's template name. A document raised
 * by a form carries the form's code ("form:staff_leave_request") as its template
 * name, which is not for people. Returns null for any other name.
 */
export function formNameFrom(templateName: string): string | null {
  const match = /^form:(.+)$/.exec(templateName);
  if (match === null) return null;
  const words = match[1].replace(/[_-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}
