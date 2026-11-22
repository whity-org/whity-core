import type { Metadata } from 'next';

/**
 * The public quote page's title (WHIT-1342). The link names one customer's
 * quote, so it is never indexed or followed.
 */
export const metadata: Metadata = {
  title: 'Quote',
  robots: { index: false, follow: false },
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
