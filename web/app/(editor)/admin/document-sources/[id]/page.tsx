'use client';

import { use, useMemo } from 'react';
import { useRouter } from 'next/navigation';
import { DocumentEditorScreen } from '@whity-org/features/document-editor';
import { useTranslation } from '@whity-org/features/i18n';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useAuth } from '@/lib/auth-context';
import { DOCUMENTS_COMMENT, DOCUMENTS_ISSUE, DOCUMENTS_SUGGEST, DOCUMENTS_WRITE } from '@/lib/capabilities';
import { issueHref } from '@/lib/documents/issue';
import { documentSourcesAdapter } from '@/lib/documents/sources';

/**
 * A document in the block editor, full-screen in the `(editor)` route group:
 * the editor's own header carries Back. People without documents:write
 * suggest (documents:suggest, E.10) or read; documents:comment comments in
 * any mode (M5).
 *
 * `?issue=1` opens it in issuing mode (editor M4c): a template shows its
 * published version with the record's values and Issue; a letter made by
 * "Edit before issuing" edits with the preview on. `&record=type:id` is the
 * record to issue for. Issuing needs documents:issue; without it the page
 * opens as usual.
 */
export default function DocumentSourcePage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { id } = use(params);
  const query = use(searchParams);
  const router = useRouter();
  const t = useTranslation('documents');
  const { hasPermission } = useCapabilities();
  const { user } = useAuth();
  // Your own cards say "You"; the id matches the actor the server sends.
  const me = useMemo(() => (user ? { kind: 'person' as const, id: String(user.id), name: user.email } : undefined), [user]);

  const issuing = query.issue === '1' && hasPermission(DOCUMENTS_ISSUE);
  const record = typeof query.record === 'string' && query.record !== '' ? query.record : null;

  return (
    <DocumentEditorScreen
      // A different mode is a different screen: remount rather than carry state over.
      key={issuing ? 'issue' : 'edit'}
      id={id}
      adapter={documentSourcesAdapter}
      canEdit={hasPermission(DOCUMENTS_WRITE)}
      canSuggest={hasPermission(DOCUMENTS_SUGGEST)}
      canComment={hasPermission(DOCUMENTS_COMMENT)}
      me={me}
      onBack={() => (issuing ? router.push('/admin/document-library') : router.push('/admin/document-sources'))}
      path={issuing ? t('blockEditor.issue.path', 'New document') : t('blockEditor.list.title', 'Notes and wiki')}
      issue={
        issuing
          ? {
              record,
              onIssued: (documentId) => router.push(`/admin/document-library/${documentId}`),
              onEditCopy: (copyId, copyRecord) => router.push(issueHref(copyId, copyRecord)),
            }
          : undefined
      }
    />
  );
}
