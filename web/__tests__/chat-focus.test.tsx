/**
 * The chat screen tells the server while a chat is open in a focused window,
 * so its lines aren't pushed to this person meanwhile (WHIT-1077).
 */

import { act, renderHook } from '@testing-library/react';
import { FOCUS_BEAT_MS, useChatFocus } from '@/components/chat/chat-screen';

jest.mock('next/navigation', () => ({ useRouter: () => ({ replace: jest.fn() }), useSearchParams: () => new URLSearchParams() }));

let focused = true;

beforeEach(() => {
  jest.useFakeTimers();
  focused = true;
  jest.spyOn(document, 'hasFocus').mockImplementation(() => focused);
  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

it('beats while the chat is open and focused, and says when it stops', () => {
  const send = jest.fn(async () => undefined);
  const { rerender, unmount } = renderHook(({ id }) => useChatFocus(id, send), { initialProps: { id: 7 as number | null } });

  expect(send).toHaveBeenLastCalledWith(7, true);
  act(() => void jest.advanceTimersByTime(FOCUS_BEAT_MS));
  expect(send).toHaveBeenCalledTimes(2);

  // The window loses focus: said at once, then quiet.
  focused = false;
  act(() => void window.dispatchEvent(new Event('blur')));
  expect(send).toHaveBeenLastCalledWith(7, false);
  act(() => void jest.advanceTimersByTime(FOCUS_BEAT_MS * 3));
  expect(send).toHaveBeenCalledTimes(3);

  // Back, then another chat: the first is let go.
  focused = true;
  act(() => void window.dispatchEvent(new Event('focus')));
  expect(send).toHaveBeenLastCalledWith(7, true);
  rerender({ id: 9 });
  expect(send).toHaveBeenCalledWith(7, false);
  expect(send).toHaveBeenLastCalledWith(9, true);

  unmount();
  expect(send).toHaveBeenLastCalledWith(9, false);
});

it('sends nothing with no chat open', () => {
  const send = jest.fn(async () => undefined);
  renderHook(() => useChatFocus(null, send));
  act(() => void jest.advanceTimersByTime(FOCUS_BEAT_MS * 2));
  expect(send).not.toHaveBeenCalled();
});
