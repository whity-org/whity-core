/**
 * #1734 — "Verify a document" before signing in: the camera starts only when
 * asked, stops whenever the scan ends or the dialog closes, follows only this
 * instance's own links, and can be bypassed by typing.
 */

import React, { useState } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { VerifyDocumentDialog } from '@/components/verify-document-dialog';

const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const TOKEN = 'b7c4'.repeat(16);

let stopTrack: jest.Mock;
let getUserMedia: jest.Mock;
let detected: string | null;

function Harness({ onChange }: { onChange?: (open: boolean) => void }) {
  const [open, setOpen] = useState(true);
  return (
    <VerifyDocumentDialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        onChange?.(next);
      }}
      siteName="Whity"
    />
  );
}

beforeAll(() => {
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
  Object.defineProperty(HTMLMediaElement.prototype, 'play', { value: () => Promise.resolve(), configurable: true });
});

beforeEach(() => {
  jest.clearAllMocks();
  detected = null;
  stopTrack = jest.fn();
  getUserMedia = jest.fn().mockResolvedValue({ getTracks: () => [{ stop: stopTrack }] });
  Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia }, configurable: true });
  (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector = class {
    static getSupportedFormats = async () => ['qr_code'];
    async detect() {
      return detected === null ? [] : [{ rawValue: detected }];
    }
  };
  // jsdom's <video> has no srcObject.
  Object.defineProperty(HTMLMediaElement.prototype, 'srcObject', { value: null, writable: true, configurable: true });
});

afterEach(() => {
  delete (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector;
});

test('explains the camera before asking for it', () => {
  render(<Harness />);
  expect(screen.getByText('Your browser will ask to use the camera.')).toBeInTheDocument();
  expect(getUserMedia).not.toHaveBeenCalled();
});

test('a scanned link from this instance stops the camera and opens the public check', async () => {
  const onChange = jest.fn();
  render(<Harness onChange={onChange} />);
  fireEvent.click(screen.getByRole('button', { name: /use the camera/i }));
  await waitFor(() => expect(getUserMedia).toHaveBeenCalled());

  detected = `${window.location.origin}/verify/${TOKEN}`;
  await waitFor(() => expect(push).toHaveBeenCalledWith(`/verify/${TOKEN}`));
  expect(stopTrack).toHaveBeenCalled();
  expect(onChange).toHaveBeenCalledWith(false);
});

test('a code for another site is refused and the scan keeps going', async () => {
  render(<Harness />);
  fireEvent.click(screen.getByRole('button', { name: /use the camera/i }));
  await waitFor(() => expect(getUserMedia).toHaveBeenCalled());

  detected = `https://evil.example/verify/${TOKEN}`;
  await waitFor(() => expect(screen.getByTestId('verify-scan-refusal')).toBeInTheDocument());
  expect(push).not.toHaveBeenCalled();
  expect(stopTrack).not.toHaveBeenCalled();
});

test('closing the dialog stops the camera', async () => {
  render(<Harness />);
  fireEvent.click(screen.getByRole('button', { name: /use the camera/i }));
  await waitFor(() => expect(getUserMedia).toHaveBeenCalled());
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
  });
  expect(stopTrack).toHaveBeenCalled();
});

test('a blocked camera says so and offers typing', async () => {
  getUserMedia.mockRejectedValueOnce(new DOMException('no', 'NotAllowedError'));
  render(<Harness />);
  fireEvent.click(screen.getByRole('button', { name: /use the camera/i }));
  expect(await screen.findByText(/The camera is blocked for this site/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /type the code/i })).toBeInTheDocument();
});

test('typing the code by hand opens the public check', async () => {
  render(<Harness />);
  fireEvent.click(screen.getByRole('button', { name: /type the code/i }));
  fireEvent.change(screen.getByLabelText('Link or code'), { target: { value: TOKEN } });
  fireEvent.click(screen.getByRole('button', { name: /check document/i }));
  expect(push).toHaveBeenCalledWith(`/verify/${TOKEN}`);
});
