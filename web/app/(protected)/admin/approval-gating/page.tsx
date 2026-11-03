import { redirect } from 'next/navigation';

// The three approval tabs have their own routes; the bare section opens the first.
export default function ApprovalGatingIndex() {
  redirect('/admin/registrations');
}
