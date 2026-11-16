import { redirect } from 'next/navigation';

/**
 * Messages became part of Chat (design 3.5, UC.1, WHIT-1093): customer
 * conversations sit in the one Chat list beside the team's chats. Old links
 * (`/admin/messages?c=42`, bookmarks, emails) open the same conversation there.
 */
export default async function MessagesPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const params = await searchParams;
  const raw = params.c;
  const id = Number(Array.isArray(raw) ? raw[0] : raw);
  redirect(Number.isInteger(id) && id > 0 ? `/chat?c=${id}&k=customer` : '/chat');
}
