import type { Metadata } from 'next';
import { joinShare } from '@/lib/share-cards/data';

/**
 * The page's title and link preview (design 3.4 `SiteShareCards`, WHIT-1041).
 * The page itself is a client component; this layout only adds metadata. The
 * picture is `opengraph-image.tsx` beside it.
 */
export async function generateMetadata({ params }: { params: Promise<{ workspace: string }> }): Promise<Metadata> {
  const share = await joinShare((await params).workspace);
  return {
    title: share.title,
    description: share.description,
    openGraph: { title: share.title, description: share.description, type: 'website' },
    twitter: { card: 'summary_large_image', title: share.title, description: share.description },
  };
}

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
