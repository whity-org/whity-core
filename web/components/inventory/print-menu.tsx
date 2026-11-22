'use client';

/**
 * Print, in English or in Arabic (IV.20, WHIT-1234). A purchase order, a goods
 * received note or a label sheet prints from the workspace's own template in
 * that language, in its document theme; the PDF is saved for the person to
 * print or send. The language is the paper's, not the screen's: an English
 * screen can print an Arabic order for a supplier who reads Arabic.
 *
 * On a server that makes no PDFs the paper is still issued (a purchase order
 * is kept as a document) and the toast says so.
 */

import { useState } from 'react';
import { useToast } from '@/lib/toast-context';
import { inventoryErrorText, savePdf, type PrintLanguage, type Printed } from '@/lib/inventory/api';
import { Button } from '@whity/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';

interface PrintMenuProps {
  /** Prints in a language: a PDF, or a {@link Printed} that may have none. */
  print: (lang: PrintLanguage) => Promise<Blob | Printed>;
  /** The saved file's name, without ".pdf". */
  fileName: string;
  label?: string;
  size?: 'sm' | 'default';
  testId?: string;
}

export function PrintMenu({ print, fileName, label, size = 'default', testId }: PrintMenuProps) {
  const t = useTranslation('admin');
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  const run = async (lang: PrintLanguage) => {
    setBusy(true);
    try {
      const result = await print(lang);
      const pdf = result instanceof Blob ? result : result.pdf;
      if (pdf === null) {
        toast.addToast(t('inventory.print.noPdf', 'Issued as a document. This server makes no PDFs, so there is nothing to save.'), 'info');
        return;
      }
      savePdf(pdf, `${fileName}${lang === 'ar' ? '-ar' : ''}`);
    } catch (error) {
      toast.addToast(inventoryErrorText(error), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size={size} loading={busy} disabled={busy} data-testid={testId}>
          <Icon name="print" />
          {label ?? t('inventory.print.action', 'Print')}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => void run('en')}>{t('inventory.print.english', 'In English')}</DropdownMenuItem>
        <DropdownMenuItem onClick={() => void run('ar')}>{t('inventory.print.arabic', 'In Arabic')}</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
