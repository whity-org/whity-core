import { redirect } from 'next/navigation';

/** A group's record is the drawer on the Tags page now; an old link opens it. */
export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  redirect(`/admin/tags?group=${encodeURIComponent(id)}`);
}
