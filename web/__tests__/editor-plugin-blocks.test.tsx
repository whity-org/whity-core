/**
 * Editor milestone M7, plugin blocks (EditorCustomBlocks; E.17–E.20): a
 * plugin's block is a declaration Whity draws. Its view is filled with the
 * block's data and drawn by Whity's renderer; its source label says Verified
 * (Whity's mark) and Prints or Notes only; Edit is a form from its fields;
 * a plugin that is off shows a placeholder, keeps the data and offers Enable
 * to those who may. Each plugin has its own group in the / menu, offering
 * only blocks allowed in this kind of document.
 */
import * as React from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { pluginBlockItems } from '@whity/ui/editor/catalogue';
import type { Block, WhityDocument } from '@whity/ui/editor/contract';
import { fromEditorContent, normalizeBlocks, toEditorContent } from '@whity/ui/editor/convert';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { fillView } from '@whity/ui/editor/plugin-blocks';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';
import type { EditorServices, PluginBlockDefinition, PluginBlocks } from '@whity/ui/editor/services';

const L = (en: string, ar = en) => ({ en, ar });

const DELIVERY: PluginBlockDefinition = {
  name: 'Shipping/delivery_status',
  blockType: 'delivery_status',
  label: L('Delivery status', 'حالة التوصيل'),
  description: L('Where an order is'),
  icon: 'truck',
  keywords: ['shipping'],
  fields: [
    { key: 'order', label: L('Order'), type: 'text', required: true },
    { key: 'stage', label: L('Stage'), type: 'select', options: [{ value: 'packed', label: L('Packed') }, { value: 'out', label: L('Out for delivery') }] },
  ],
  view: [{ type: 'keyValue', items: [{ label: 'Order', value: '{{data.order}}' }, { label: 'Stage', value: '{{data.stage}}' }] }],
  print: [{ type: 'text', value: '{{data.order}}' }],
  allowedIn: ['note', 'template'],
};
const ROUTE: PluginBlockDefinition = { ...DELIVERY, name: 'Shipping/route', blockType: 'route', label: L('Route map'), print: null, allowedIn: ['note', 'wiki'] };

const BLOCKS: PluginBlocks = {
  plugins: [{ plugin: 'Shipping', label: L('Shipping'), verified: true, firstParty: false, blocks: [DELIVERY, ROUTE] }],
  off: [{ plugin: 'DeliveryTracker', label: L('Delivery Tracker') }],
  canEnable: true,
};

const BLOCK: Block = { id: 'd1', type: 'plugin', plugin: 'Shipping', blockType: 'delivery_status', schemaVersion: 1, data: { order: 'SO-2207', stage: 'out' } };

/** Stands in for Whity's screen renderer: a keyValue as "label: value" lines. */
const renderPluginView = (view: unknown[]) => (
  <dl>
    {(view as { items: { label: string; value: string }[] }[]).flatMap((n) => n.items.map((i) => <div key={i.label}>{`${i.label}: ${i.value}`}</div>))}
  </dl>
);

function services(over: Partial<EditorServices> = {}): EditorServices {
  return { pluginBlocks: BLOCKS, renderPluginView, ...over };
}

beforeAll(() => {
  global.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
  const rects = () => Object.assign([], { item: () => null }) as unknown as DOMRectList;
  Element.prototype.getClientRects ??= rects;
  Range.prototype.getClientRects ??= rects;
  Range.prototype.getBoundingClientRect ??= () => ({ x: 0, y: 0, top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, toJSON: () => ({}) }) as DOMRect;
});

it('round-trips a plugin block, and fills a view with its data in the reader\'s language', () => {
  expect(fromEditorContent(toEditorContent([BLOCK]))).toEqual(normalizeBlocks([BLOCK]));
  expect(fillView([{ type: 'heading', level: 2, text: { en: 'Order {{data.order}}', ar: 'الطلب {{data.order}}' } }], { order: 'SO-2207' }, 'ar')).toEqual([
    { type: 'heading', level: 2, text: 'الطلب SO-2207' },
  ]);
});

it('gives each plugin its own group, with only the blocks this kind of document may hold', () => {
  const inNote = pluginBlockItems(BLOCKS, 'note', 'en');
  expect(inNote.map((i) => [i.groupTitle, i.title, i.hintText])).toEqual([
    ['Shipping', 'Delivery status', 'Where an order is'],
    ['Shipping', 'Route map', 'Where an order is'],
  ]);
  expect(inNote[0].node).toEqual({ type: 'plugin', attrs: { plugin: 'Shipping', blockType: 'delivery_status', schemaVersion: 1, data: { order: '', stage: 'packed' } } });
  // E.18: without a print view, never in a template.
  expect(pluginBlockItems(BLOCKS, 'template', 'ar').map((i) => i.title)).toEqual(['حالة التوصيل']);
});

it('draws the block from its declaration, with its source label, and edits its data in a form', async () => {
  const onChange = jest.fn();
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [BLOCK] };
  render(<DocEditor document={doc} onChange={onChange} canEdit services={services()} />);
  await act(async () => {});

  const block = screen.getByRole('group', { name: 'Delivery status, from Shipping' });
  expect(block).toHaveTextContent('Order: SO-2207');
  expect(block).toHaveTextContent('Stage: Out for delivery');
  expect(within(block).getByText(EDITOR_LABELS.pluginVerified)).toBeInTheDocument();
  expect(within(block).getByText(EDITOR_LABELS.pluginPrints)).toBeInTheDocument();

  fireEvent.click(within(block).getByRole('button', { name: EDITOR_LABELS.pluginEdit }));
  const order = await screen.findByLabelText('Order');
  fireEvent.change(order, { target: { value: 'SO-2208' } });
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.pluginSave }));
  await act(async () => {});
  const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks[0]).toMatchObject({ type: 'plugin', data: { order: 'SO-2208', stage: 'out' } });
});

it('says when a block can\'t print, and that a plugin is not verified', async () => {
  const unverified: PluginBlocks = { ...BLOCKS, plugins: [{ ...BLOCKS.plugins[0], verified: false }] };
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [{ ...BLOCK, blockType: 'route' } as Block] };
  render(<DocEditor document={doc} onChange={jest.fn()} canEdit services={services({ pluginBlocks: unverified })} />);
  await act(async () => {});
  const block = screen.getByRole('group', { name: 'Route map, from Shipping' });
  expect(within(block).getByText(EDITOR_LABELS.pluginNotVerified)).toBeInTheDocument();
  expect(within(block).getByText(EDITOR_LABELS.pluginNotesOnly)).toBeInTheDocument();
});

it('shows the plugin-off placeholder, keeps the data, and offers Enable to those who may', async () => {
  const enablePlugin = jest.fn().mockResolvedValue(undefined);
  const onChange = jest.fn();
  const off: Block = { id: 'g1', type: 'plugin', plugin: 'DeliveryTracker', blockType: 'status', schemaVersion: 1, data: { order: 'SO-1' } };
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [off] };
  const { unmount } = render(<DocEditor document={doc} onChange={onChange} canEdit services={services({ enablePlugin })} />);
  await act(async () => {});

  expect(screen.getByText('Needs the Delivery Tracker plugin')).toBeInTheDocument();
  expect(screen.getByText(EDITOR_LABELS.pluginNeedsHint)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.pluginEnable }));
  await waitFor(() => expect(enablePlugin).toHaveBeenCalledWith('DeliveryTracker'));
  expect(onChange).not.toHaveBeenCalled();
  unmount();

  // Without plugins:enable, or for a plugin that isn't installed here: no Enable.
  render(<DocEditor document={doc} onChange={jest.fn()} canEdit services={services({ enablePlugin, pluginBlocks: { ...BLOCKS, canEnable: false } })} />);
  await act(async () => {});
  expect(screen.queryByRole('button', { name: EDITOR_LABELS.pluginEnable })).toBeNull();
});
