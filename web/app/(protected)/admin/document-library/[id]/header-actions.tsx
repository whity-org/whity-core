'use client';

/**
 * The record's header actions (A30): Download PDF and Print as buttons, and a
 * ⋯ menu that holds the rare ones, Add to a collection and Withdraw this document.
 * Withdrawing asks first (tier 2): it cannot be undone. A withdrawn document keeps
 * Download and Print, because the record is kept and someone may need the paper.
 *
 * The PDF is fetched through `apiClient` because its address is an API path that
 * needs the session; a plain link would arrive without it. The current version
 * is the newest artifact (`artifacts` is newest first).
 */

import { useCallback, useState } from 'react';
import { IconDots, IconDownload, IconFolderPlus, IconLock, IconPrinter } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_WITHDRAW } from '@/lib/capabilities';

import { FileIntoCollectionDialog } from '../collection-dialogs';
import type { DocumentCollection } from '../types';
import type { DocumentRecord } from './types';

export interface DocumentHeaderActionsProps {
  record: DocumentRecord;
  onWithdrawn: () => void;
}

/** A file name the browser will accept: the title with path characters removed. */
function pdfName(title: string): string {
  const cleaned = title.replace(/[\\/:*?"<>|]+/g, ' ').trim();
  return `${cleaned === '' ? 'document' : cleaned}.pdf`;
}

export function DocumentHeaderActions({ record, onWithdrawn }: DocumentHeaderActionsProps) {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const current = record.artifacts[0] ?? null;

  const [busy, setBusy] = useState<'download' | 'print' | null>(null);
  const [filing, setFiling] = useState(false);
  const [collections, setCollections] = useState<DocumentCollection[]>([]);
  const [filedIn, setFiledIn] = useState<number[]>(record.collection_ids ?? []);
  const [filingBusy, setFilingBusy] = useState(false);
  const [withdrawing, setWithdrawing] = useState(false);
  const { has } = useCapabilities();
  const canWithdraw = has(DOCUMENTS_WITHDRAW) && (record.withdrawn_at ?? null) === null;

  const fetchPdf = useCallback(async (): Promise<Blob> => {
    if (current === null) throw new Error('no artifact');
    const response = await apiClient(current.content_url);
    if (!response.ok) throw new Error(t('record.actions.error', 'The PDF could not be fetched'));
    return response.blob();
  }, [apiClient, current, t]);

  const download = useCallback(async () => {
    setBusy('download');
    try {
      const url = URL.createObjectURL(await fetchPdf());
      const link = window.document.createElement('a');
      link.href = url;
      link.download = pdfName(record.title);
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
    } catch (error) {
      addToast(error instanceof Error ? error.message : String(error), 'error');
    } finally {
      setBusy(null);
    }
  }, [addToast, fetchPdf, record.title]);

  const print = useCallback(async () => {
    setBusy('print');
    try {
      const url = URL.createObjectURL(await fetchPdf());
      const frame = window.document.createElement('iframe');
      frame.style.cssText = 'position:fixed;inset-inline-end:0;bottom:0;width:0;height:0;border:0';
      frame.src = url;
      frame.onload = () => {
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
        setTimeout(() => {
          frame.remove();
          URL.revokeObjectURL(url);
        }, 60_000);
      };
      window.document.body.appendChild(frame);
    } catch (error) {
      addToast(error instanceof Error ? error.message : String(error), 'error');
    } finally {
      setBusy(null);
    }
  }, [addToast, fetchPdf]);

  const openFiling = useCallback(async () => {
    setFiling(true);
    try {
      const response = await apiClient('/api/v1/document-collections');
      if (!response.ok) throw new Error(t('organizer.error.collections', 'Failed to load your collections'));
      const body = (await response.json()) as { data?: DocumentCollection[] };
      setCollections(body.data ?? []);
    } catch (error) {
      setFiling(false);
      addToast(error instanceof Error ? error.message : String(error), 'error');
    }
  }, [addToast, apiClient, t]);

  const toggle = useCallback(
    async (collectionId: number, next: boolean) => {
      setFilingBusy(true);
      try {
        const response = await apiClient(
          `/api/v1/document-collections/${collectionId}/documents/${record.id}`,
          { method: next ? 'PUT' : 'DELETE' }
        );
        if (!response.ok) {
          throw new Error(
            t('organizer.error.file', 'Failed to change what this document is filed in')
          );
        }
        setFiledIn((ids) => (next ? [...ids, collectionId] : ids.filter((id) => id !== collectionId)));
      } catch (error) {
        addToast(error instanceof Error ? error.message : String(error), 'error');
      } finally {
        setFilingBusy(false);
      }
    },
    [addToast, apiClient, record.id, t]
  );

  const withdraw = useCallback(async () => {
    const response = await apiClient(`/api/v1/documents/${record.id}/withdraw`, { method: 'POST' });
    if (!response.ok) {
      addToast(t('record.withdraw.error', 'The document could not be withdrawn'), 'error');
      return;
    }
    addToast(t('record.withdraw.done', 'The document was withdrawn'), 'success');
    onWithdrawn();
  }, [addToast, apiClient, onWithdrawn, record.id, t]);

  const noFile = t('record.actions.noFile', 'Nothing has been rendered from this document yet');

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="document-record-actions">
      <Button
        variant="outline"
        disabled={current === null || busy !== null}
        title={current === null ? noFile : undefined}
        onClick={() => void download()}
      >
        <IconDownload className="size-4 me-1" aria-hidden />
        {t('record.actions.download', 'Download PDF')}
      </Button>
      <Button
        variant="outline"
        disabled={current === null || busy !== null}
        title={current === null ? noFile : undefined}
        onClick={() => void print()}
      >
        <IconPrinter className="size-4 me-1" aria-hidden />
        {t('record.actions.print', 'Print')}
      </Button>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" aria-label={t('record.actions.more', 'More actions')}>
            <IconDots className="size-4" aria-hidden />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => void openFiling()}>
            <IconFolderPlus className="size-4 me-2" aria-hidden />
            {t('organizer.table.fileInto', 'Add to collection…')}
          </DropdownMenuItem>
          {canWithdraw && (
            <DropdownMenuItem variant="destructive" onSelect={() => setWithdrawing(true)}>
              <IconLock className="size-4 me-2" aria-hidden />
              {t('record.withdraw.action', 'Withdraw this document')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      <ConfirmAction
        open={withdrawing}
        onOpenChange={setWithdrawing}
        title={t('record.withdraw.title', 'Withdraw {title}?', { title: record.title })}
        description={t(
          'record.withdraw.consequence',
          'Its printed codes stop confirming it, anyone waiting on it is no longer asked, and it cannot be sent on or corrected. The record and its history are kept. This cannot be undone.'
        )}
        confirmLabel={t('record.withdraw.confirm', 'Withdraw document')}
        cancelLabel={t('record.withdraw.cancel', 'Keep it')}
        onConfirm={withdraw}
      />

      <FileIntoCollectionDialog
        documentRow={filing ? { ...record, collection_ids: filedIn } : null}
        onClose={() => setFiling(false)}
        collections={collections}
        busy={filingBusy}
        onToggle={(id, next) => void toggle(id, next)}
        onCreateNew={() => setFiling(false)}
      />
    </div>
  );
}
