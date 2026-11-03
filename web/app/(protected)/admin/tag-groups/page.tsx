import { redirect } from 'next/navigation';

/** Tag groups are on the one Tags page now (A46); the old address still works. */
export default function TagGroupsPage() {
  redirect('/admin/tags');
}
