import { CARD_SIZE, renderCard } from '@/lib/share-cards/card';
import { verifyShare } from '@/lib/share-cards/data';

/** The link preview (design 3.4 `SiteShareCards`, WHIT-1041). */
export const size = CARD_SIZE;
export const contentType = 'image/png';
export const alt = 'Link preview';

export default async function Image({ params }: { params: Promise<{ token: string }> }) {
  return renderCard((await verifyShare((await params).token)).card);
}
