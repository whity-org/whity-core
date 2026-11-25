# @whity/plugin-sdk

What a Whity plugin's frontend needs, in one package versioned with the PHP SDK (`whity/plugin-sdk`): the same version number, so `1.170.0` here matches SDK 1.170.

- **The block contract**, generated from the PHP SDK: every block type, its child slots and its props (`BLOCK_CONTRACT`, `BLOCK_TYPES`, `MAX_DEPTH`, `MAX_NODES`, `FLOW_MAX_NODES`), and `SDK_VERSION`.
- **The block types**: `Block` and one interface per block type (`HeadingBlock`, `DataTableBlock`, …), as the host serves them.
- **`block()`**, a typed builder: `block('heading', { level: 2, text: "Today's orders" })`. A missing or misspelt prop is a type error.
- **`createPluginClient()`** for a plugin's own routes, on the host app's requester, and **`fetchAllPages()`**, which walks a paginated list and tells you whether it got all of it.
- **`LocalizedText`**, one `{ ar, en }` shape, with `resolveText()` and `normalizeText()` behaving like the PHP SDK.
- **Test helpers** at `@whity/plugin-sdk/testing`: `walkBlocks()`, `findBlocks()` and `createMockRequester()`.

```ts
import { block, createPluginClient } from '@whity/plugin-sdk';

const routes = createPluginClient({ request: (url, init) => fetch(url, { ...init, credentials: 'include' }) });
const today = await routes.get<{ id: number; cake: string }[]>('/api/cake-orders/today');

const screen = [
  block('heading', { level: 2, text: "Today's orders" }),
  block('dataTable', { source: '/api/cake-orders/today', columns: [{ key: 'cake', label: 'Cake' }] }),
];
```

## Keeping it in step

- `src/contract.generated.ts` is written by `php scripts/plugin-sdk-contract.php` from the repository root. Run it when the block contract or the SDK version changes; `tests/Sdk/PluginSdkPackageTest.php` fails when it is stale.
- `npm run typecheck` checks every block interface against that contract: the same props, and every prop the contract requires is required here (`test/contract.test.ts`).
- The version in `package.json` is the SDK's; the tests fail when they differ.

The package is a workspace package for now. It is published with a release, not from a pull request.
