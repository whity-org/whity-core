/**
 * Coming back to the customer area after signing in (WHIT-1416, CA.5).
 *
 * A password sign-in reads `?next=` on /login. A Whity ID or other provider
 * round trip always lands on the app's home, so the area also keeps the page
 * the person asked for in this tab's session storage, and the signed-in shell
 * takes it once. Only `/@handle` paths are ever kept or followed, so neither
 * can send anyone off the site.
 */

const KEY = 'whity.customer_area.return';

/** `/@handle` or `/@handle/...`, path only, same site. */
export function isCustomerAreaPath(path: string | null | undefined): path is string {
  return typeof path === 'string' && /^\/@[a-z0-9][a-z0-9-]{0,63}(\/[A-Za-z0-9/_-]{0,200})?$/.test(path);
}

export function rememberCustomerAreaReturn(path: string): void {
  if (!isCustomerAreaPath(path)) return;
  try {
    window.sessionStorage.setItem(KEY, path);
  } catch {
    // Private mode or storage off: the password path still has ?next=.
  }
}

/** The kept page, once; null when there is none. */
export function takeCustomerAreaReturn(): string | null {
  try {
    const path = window.sessionStorage.getItem(KEY);
    window.sessionStorage.removeItem(KEY);
    return isCustomerAreaPath(path) ? path : null;
  } catch {
    return null;
  }
}
