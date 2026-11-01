# Releasing packages

How the publishable packages in this monorepo are versioned and released.

Related: [Development Workflow](Development-Workflow.md), [Component Library](Component-Library.md).

---

## The model: one repo, independently-versioned packages

Whity is a **monorepo**, not a set of split repos — the backend and the web app
are bound by the OpenAPI contract, so changing an endpoint, regenerating the
typed client, and updating the UI happen in **one atomic PR** with one CI run.

But the genuinely reusable pieces are **published packages with their own
versions**, released on their own cadence:

| Package | Path | Registry | Notes |
|---|---|---|---|
| `@whity/ui` | `packages/ui` | npm (public) | shared React component library |
| `@whity/features` | `packages/features` | npm (public) | client-safe feature UI (adapter pattern, nav contract, sync UI) for non-Next clients (Tauri/Vite SPA, Flutter) |
| `@whity/tokens` | `packages/tokens` | npm (public) | design tokens (CSS vars, Dart/Flutter export) |
| `@whity/api-client` | `packages/api-client` | npm (public) | typed client for the Whity API, generated from the OpenAPI spec |
| `whity/plugin-sdk` | `sdk` | Composer | PHP SDK for plugin authors |
| `web` | `web` | — (private) | the reference app; deployed, never published |

The backend (`whity-org/whity-core`) ships as a Docker image, not a package.

## How a JS package release works (changesets)

Versioning is driven by [changesets](https://github.com/changesets/changesets),
so every version bump is **intentional and reviewed**, and each package moves on
its own semver.

1. **Make your change** to `packages/ui` (or another publishable package) in a
   normal PR.
2. **Record the release intent** — from the repo root:
   ```bash
   npm run changeset
   ```
   Pick the package(s) and the bump (patch / minor / major) and write a one-line
   summary. This adds a small markdown file under `.changeset/`. Commit it with
   your PR. (A change with no user-facing package impact needs no changeset.)
3. **Apply the bumps** when you're ready to cut a release — from root:
   ```bash
   npm run version-packages
   ```
   This consumes the pending changesets, bumps each affected `package.json`, and
   writes/updates its `CHANGELOG.md`. Commit the result.
4. **Merge to `main`.** The `Publish @whity/ui` workflow runs on any push
   touching `packages/ui`, but it is **version-gated**: it publishes only when
   the `package.json` version isn't already in the registry, otherwise it skips
   with a notice. So a merge with a fresh version publishes; a merge that didn't
   bump the version is a clean no-op (never a red build).

> The `web` app is listed in `.changeset` `ignore` — it is private and deployed,
> so it is never versioned or published.

## Why version-gating matters

`npm publish` fails with a `409 Conflict` if the version already exists. Running
it unconditionally on every `packages/ui` change turned `main` red whenever a
change didn't bump the version. The workflow now checks the registry first and
only publishes a genuinely new version — bumps release, incidental edits don't.

## Where the packages are published: public npm, `@whity` scope

The four JS packages are published to the public npm registry
(`https://registry.npmjs.org`) under the **`@whity`** npm organisation. Each
`package.json` says so in its `publishConfig`
(`{ "access": "public", "registry": "https://registry.npmjs.org" }`), and its
`repository` field points at this repo with the package's `directory`, which
npm needs to match a provenance statement to the source.

| Package | Workflow that publishes it |
|---|---|
| `@whity/tokens` | `.github/workflows/publish-tokens.yml` |
| `@whity/ui` | `.github/workflows/publish-ui.yml` |
| `@whity/features` | `.github/workflows/publish-features.yml` |
| `@whity/api-client` | `.github/workflows/publish-api-client.yml` |

Before WHIT-834 the packages were named `@whity-org/*` and went to GitHub
Packages. Nothing is published there any more.

### Trusted publishing: no npm token anywhere

The workflows use npm
[trusted publishing](https://docs.npmjs.com/trusted-publishers): the job has
`permissions: id-token: write`, GitHub Actions issues it an OIDC token, and npm
exchanges that for a short-lived credential that can publish **only** the
package whose Trusted Publisher names this repo and workflow file. So:

- there is no `NPM_TOKEN` or `NODE_AUTH_TOKEN` secret to create, leak or rotate;
- the workflows install npm 11.5.1 or newer (`npm install -g npm@latest`),
  which trusted publishing requires;
- they run `npm publish --provenance --access public`, so every version on
  npmjs.com carries a signed provenance statement linking it to the commit and
  workflow run that built it.

### One-time owner setup (per package)

npm only lets you configure a Trusted Publisher on a package that already
exists, so each package needs one manual publish first. An owner of the `@whity`
npm organisation does this once, from a clean checkout of `main`:

1. **First publish, by hand.** Sign in with `npm login` (2FA on), then from
   each package directory run `npm publish --access public`. Publish in
   dependency order: `packages/tokens`, then `packages/ui` (depends on tokens),
   then `packages/features` (depends on ui), then `packages/api-client`. Publish
   the version that is already in `package.json`; the workflow's version gate
   then skips it and publishes from the next bump on.
2. **Configure the Trusted Publisher on npmjs.com.** For each package, open
   *Package settings → Trusted Publisher → GitHub Actions* and enter:
   - Organization or user: `whity-org`
   - Repository: `whity-core`
   - Workflow filename: the file from the table above, e.g. `publish-ui.yml`
     (just the file name, not the path)
   - Environment: leave empty
3. **Optional hardening.** Once a release has gone out through the workflow, set
   *Publishing access* to "Require two-factor authentication and disallow
   tokens", so only the trusted workflow (or a person with 2FA) can publish.
4. Sign out with `npm logout`. No token is stored in the repo or its secrets.

If a workflow run fails with `ENEEDAUTH` or a 404 on `PUT`, the Trusted
Publisher for that package is missing or names a different workflow file.

## Consuming a package from a DOWNSTREAM repo (not this monorepo)

The packages are public on npmjs.com, so a downstream repo installs them like
any other npm package. No `.npmrc`, scope mapping or token is needed, locally or
in CI:

```bash
npm install @whity/ui @whity/tokens
```

If a downstream repo still has an `.npmrc` line mapping
`@whity-org:registry=https://npm.pkg.github.com`, delete it and switch its
imports from `@whity-org/*` to `@whity/*`.

To check what's published: `npm view @whity/ui versions`. A version's
provenance is shown on its npmjs.com page and by `npm audit signatures`.

## PHP SDK (`whity/plugin-sdk`)

The plugin SDK is a Composer package versioned by git tag (semver). Tag a
release (`vX.Y.Z`) when its public surface changes; plugin authors pin a range.
Keep it backward-compatible within a major so existing plugins keep working.
