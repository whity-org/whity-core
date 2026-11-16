/**
 * Files, photos and voice in Chat (design 3.5, UC.10, WHIT-1095).
 *
 * What would go wrong unnoticed: a voice message without its words, a file
 * with no way to download it, a list line that says nothing for a message of
 * files only, and a file that didn't go without Try again.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen } from '@testing-library/react';
import { MessageAttachments, PendingFiles, attachmentsLine, duration, fileSize, type PendingFile } from '@/components/chat/attachments';
import type { ChatAttachment } from '@/lib/chat-wire';

const t = (_key: string, fallback: string, values?: Record<string, string | number>) =>
  Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback);

function file(over: Partial<ChatAttachment>): ChatAttachment {
  return {
    id: 1,
    kind: 'file',
    content_type: 'application/pdf',
    byte_size: 1258291,
    filename: 'Price list 2027.pdf',
    width: null,
    height: null,
    duration_ms: null,
    transcript: null,
    message_id: 9,
    url: '/api/v1/chat/attachments/1',
    ...over,
  };
}

test('a file shows its type, name and size, with Download', () => {
  render(<MessageAttachments t={t} attachments={[file({})]} />);

  expect(screen.getByText('PDF')).toBeInTheDocument();
  expect(screen.getByText('Price list 2027.pdf')).toBeInTheDocument();
  expect(screen.getByText('1.2 MB')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Download Price list 2027.pdf' })).toHaveAttribute('href', '/api/v1/chat/attachments/1');
});

test('photos sent together show as an album', () => {
  render(
    <MessageAttachments
      t={t}
      attachments={[
        file({ id: 2, kind: 'image', content_type: 'image/jpeg', filename: 'box.jpg', url: '/a/2' }),
        file({ id: 3, kind: 'image', content_type: 'image/jpeg', filename: 'ribbon.jpg', url: '/a/3' }),
      ]}
    />
  );

  expect(screen.getAllByRole('img').map((i) => i.getAttribute('alt'))).toEqual(['box.jpg', 'ribbon.jpg']);
});

test('a voice message plays in place with its length and its words', () => {
  render(<MessageAttachments t={t} attachments={[file({ id: 4, kind: 'voice', content_type: 'audio/webm', duration_ms: 18000, transcript: 'Six left for tomorrow.' })]} />);

  expect(screen.getByRole('button', { name: 'Play voice message' })).toBeInTheDocument();
  expect(screen.getByText('0:18')).toBeInTheDocument();
  expect(screen.getByText('Six left for tomorrow.')).toBeInTheDocument();
});

test('a message of files only still says what it is', () => {
  expect(attachmentsLine(t, [file({ kind: 'voice', duration_ms: 7000 })])).toBe('Voice message · 0:07');
  expect(attachmentsLine(t, [file({ kind: 'image' }), file({ kind: 'video' })])).toBe('2 photos');
  expect(attachmentsLine(t, [file({ kind: 'image' })])).toBe('Photo');
  expect(attachmentsLine(t, [file({})])).toBe('Price list 2027.pdf');
  expect(attachmentsLine(t, [])).toBe('');
});

test('a file that did not go says so, keeps its place, and offers Try again', async () => {
  const onRetry = jest.fn();
  const onRemove = jest.fn();
  const failed: PendingFile = { key: 'k1', file: new Blob(['x']), name: 'box.png', kind: 'image', progress: 0.6, status: 'failed' };
  const going: PendingFile = { key: 'k2', file: new Blob(['x']), name: 'price.pdf', kind: 'file', progress: 0.4, status: 'uploading' };
  render(<PendingFiles t={t} files={[failed, going]} onRetry={onRetry} onRemove={onRemove} />);

  expect(screen.getByText('Not sent. No connection.')).toBeInTheDocument();
  expect(screen.getByRole('progressbar', { name: 'Sending price.pdf' })).toHaveAttribute('aria-valuenow', '40');
  await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
  expect(onRetry).toHaveBeenCalledWith('k1');
  await userEvent.click(screen.getByRole('button', { name: 'Remove box.png' }));
  expect(onRemove).toHaveBeenCalledWith('k1');
});

test('sizes and lengths read plainly', () => {
  expect(fileSize(340 * 1024)).toBe('340 KB');
  expect(duration(125000)).toBe('2:05');
});
