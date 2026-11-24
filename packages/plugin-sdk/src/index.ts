/**
 * @whity/plugin-sdk (SDK 1.165, decision D8): what a plugin's frontend, the web
 * app and the desktop template share. Its version moves in lockstep with the
 * PHP SDK (`whity/plugin-sdk`), and `SDK_VERSION` says which one it matches.
 *
 * - The block contract, generated from the PHP SDK, and the block types.
 * - `block()`, a typed builder.
 * - `createPluginClient()` for a plugin's own routes, and `fetchAllPages()`.
 * - `LocalizedText`, one `{ar, en}` shape, with `resolveText()`.
 * - Test helpers under `@whity/plugin-sdk/testing`.
 */
export * from './contract.generated';
export * from './blocks';
export { block, type BlockOf, type BlockProps } from './builder';
export * from './client';
export * from './localized';
