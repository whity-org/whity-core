import { redirect } from 'next/navigation';

/**
 * Circulation is the Routing tab of the document record (design 2.9, D.3). The
 * old address stays so saved links and notifications still land somewhere.
 */
export default async function DocumentRoutingRedirect({
  params,
}: {
  params: Promise<{ documentId: string }>;
}) {
  const { documentId } = await params;
  redirect(`/admin/document-library/${encodeURIComponent(documentId)}?tab=routing`);
}
