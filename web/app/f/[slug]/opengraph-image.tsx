import { CARD_SIZE, renderCard } from '@/lib/share-cards/card';
import { formShare } from '@/lib/share-cards/data';

/** The link preview (design 3.4 `SiteShareCards`, WHIT-1041). */
export const size = CARD_SIZE;
export const contentType = 'image/png';
export const alt = 'Link preview';

export default async function Image({ params }: { params: Promise<{ slug: string }> }) {
  return renderCard((await formShare((await params).slug)).card);
}
