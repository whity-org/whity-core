# 38 · A2UI: what agent-drawn screens could do for Whity

**Status: evaluation (WHIT-1378), waiting for the owner's answers (section 9). Nothing here is built and nothing in Whity changes with this section.** The proposed register rows are AU.1–AU.8 (section 8). They go into `00-decision-register.md` only once the owner has answered.

The owner, 22 November 2026: "Follow the steps at https://a2ui.org/quickstart/agent.md to set up the A2UI quickstart web and mobile apps and explain them to me" and "check a2ui and what can we make it help whity".

**The short answer.** A2UI is the same idea as our plugin blocks (section 3), done as an open standard with official renderers for web, Flutter, iOS and Android. It is worth a **pilot**, not adoption. Two places make sense: an agent's proposal that the person can edit before approving, and plugin screens on the Flutter app, which has no blocks renderer today. Plugin blocks stay as they are. Agent-drawn surfaces never get to act on their own. Every press goes through `call_operation` and the agent's mode, exactly like any other agent action.

## 1. What A2UI is, in plain words

A2UI ("agent to user interface") lets an agent answer with a small screen instead of a paragraph. The agent never sends code or HTML. It sends a description: "a card, with a date picker bound to `/trip/startDate`, and a button called *Search*". The app draws that description with components it already ships. If a component isn't in the app's list (its **catalog**), it can't be drawn.

Five words cover it:

| Word | Meaning | Whity's nearest thing |
| --- | --- | --- |
| **Catalog** | the list of components the app can draw, each with a JSON Schema for its inputs | `BlockContract.php`, the 33 block types |
| **Surface** | one screen or card, with an id, that the agent can create, update or delete | a blocks screen, or an `ActionProposal` card |
| **Data model** | values attached to a surface (`/trip/startDate`). Components bind to paths, and the screen redraws when a value changes, with no trip to the agent | none; blocks fetch their data from plugin routes (`source`) |
| **Action** | a press, sent back to the agent as `{name, surfaceId, sourceComponentId, context}` | a block `form` / `actionButton` calling a plugin endpoint |
| **Function** | a small named helper the client runs (`formatCurrency`, `required`, `openUrl`) | the formatting and validation the renderer does for blocks |

Messages go one way as `createSurface`, `updateComponents`, `updateDataModel` and `deleteSurface`, and back as an action, an error, and the renderer's capabilities and data model. A2UI defines the messages, not the pipe. The sample uses server-sent events, and there is a guide for carrying it over MCP (section 5).

Maintained in `github.com/a2ui-project/a2ui` (Google copyright on the code, Apache 2.0). Spec v1.0 is "Current". Official renderers: React, Lit, Angular, Flutter (`a2ui_flutter`), SwiftUI and Jetpack Compose. Agent SDKs: Python, TypeScript and Dart. There is no PHP SDK.

## 2. The quickstart, run on this machine

Set up on 22 November 2026 in `D:\Projects\Whity\99-Archive\a2ui-quickstart` (outside every Whity repo), from `a2ui-project/labs` at commit `61e9bcd`, sample `samples/travel-a2ui`.

**What it downloaded:** about 45 Python packages into a local `.venv` (FastAPI, uvicorn, Google's `google-genai`, `a2ui-agent-sdk`, `a2ui-core`), the sample's npm packages (`@a2ui/react`, `@a2ui/web_core`, Vite), and the Flutter packages `a2ui_flutter` and `a2ui_core`. Nothing went in system-wide. Nothing listens beyond `127.0.0.1:8080`.

**What it took on Windows** (the script is bash for macOS and Linux):

- `uvloop` is in the lock file and has no Windows build. Left out; uvicorn runs without it.
- `npm ci` refused, because the sample's lock file is out of step with npm 11. `npm install` worked, but it skipped the bundler's Windows binary (`@rolldown/binding-win32-x64-msvc`), which had to be added by hand.
- Git Bash rewrote `--base-href=/flutter/` into a Windows path. `MSYS_NO_PATHCONV=1` fixed it.
- The README asks for Flutter 3.47+. 3.44.6 built it fine.

None of this matters for Whity. It does show the sample is young (its own README: "for demonstration only and is not meant for production use").

### 2.1 The web app (React), http://127.0.0.1:8080

A travel planner. You type "JFK to Madrid, 12th to the 19th of April, two of us". The agent (Gemini) answers with text **and** with cards it composed for that answer: flight options you tap, hotel cards, date and traveller pickers, a price summary. With no Gemini key, the app plays a recorded sample trip, which is what ran here.

- **Chat** holds the conversation. Each agent turn adds an *inline* surface under its reply. Older surfaces go grey and stop accepting input, so you can't answer a question the conversation has moved past.
- **The panel on the right** ("Your trip to Madrid") is one standing surface. The agent drew it once. After that, the server only updates its data, so it never needs redrawing.
- **Home** is a third surface: where the trip stands today.
- **Catalog** draws all 33 components (18 from the A2UI basic set, 15 travel ones) with the real renderer. No agent is involved.
- **Wire** shows, for every surface, the A2UI JSON the browser received and the shorter "Express" text the model actually wrote.

### 2.2 The "mobile" app (Flutter), http://127.0.0.1:8080/flutter/

The same agent and the same messages, drawn by Flutter (`a2ui_flutter`) in a phone-shaped frame. It is built for the web. The sample doesn't set up iOS or Android builds, so no emulator or SDK was needed. It is the proof the project is built around: neither client knows anything about travel, and both draw the same surfaces from the same JSON.

**What blocks it here:** the Flutter app has no recorded sample. It opens on "A Gemini API key" and goes no further without one. The build and the server work. Talking to it needs a Gemini key, which this session did not enter. The owner can paste one into the page to try it.

### 2.3 How the pieces talk

```
 you type or press
        │
        ▼
 client (React or Flutter) ──POST /api/chat──▶ Python server ──▶ Gemini
   sends: your text, the last press (A2UI action),          │
   which catalogs it can draw, the data model               │ model writes "Express":
        ▲                                                    │ Flights(...), Text(...)
        │                                                    ▼
        └──── server-sent events: A2UI messages ◀── server checks it against the
              createSurface / updateComponents /       catalog with the A2UI SDK,
              updateDataModel                          expands shortcuts, sends only
                                                       what is valid
```

- **Agent:** the Python server plus Gemini. The model is taught the catalog by a generated "skill" document, so it can't name a component that doesn't exist.
- **Protocol:** A2UI v1.0 JSON. The server validates every message before sending it. A message that wouldn't apply is never sent. The renderer reports anything it refused, and the model redraws.
- **Renderer:** `@a2ui/react` or `a2ui_flutter`, plus the app's own widgets for each catalog entry.

Two rules from the sample's architecture notes are worth keeping whatever we do. "Anything that must not vary belongs in the host, not the prompt." And the model owns the surface's layout, while the host owns the data.

## 3. A2UI next to plugin blocks

| | Plugin blocks (`docs/wiki/Plugin-UI-Blocks.md`, SDK v1.8) | A2UI v1.0 |
| --- | --- | --- |
| Who writes the tree | a plugin author, ahead of time | an agent, per answer (or a server, ahead of time) |
| Vocabulary | 33 fixed types, semantic props only | basic catalog of 18 plus any custom catalog the app declares |
| Validation | `BlockValidator.php`, on the server, fail closed; max depth 32, 500 nodes | JSON Schema per catalog; SDK validators in Python, TS, Dart |
| Data | `source` = a route the same plugin registered | a data model on the surface, bound by path, updated live |
| Presses | `form` / `actionButton` → the plugin's own endpoint, whose `requiredPermission` must match | an action back to the agent; or a client function |
| Renderers | web (`block-renderer.tsx`) and desktop (`templates/tauri-desktop/src/plugin-blocks/`); **no Flutter** | React, Lit, Angular, Flutter, SwiftUI, Compose |
| Styling | Whity tokens | v1.0 dropped `theme`; components take the host app's theme |
| Streaming and partial updates | no | yes |

**They are the same shape.** Both are a JSON tree of allowed components that the client draws natively, with no code. The difference is who writes it and when. Blocks are written once, by a plugin author we can review. A2UI is written live, by a model we can't.

**Replace blocks with A2UI?** No. Blocks shipped in SDK v1.8 and plugins depend on them. Their security comes from server rules A2UI doesn't have (endpoint ownership, matching permissions, `accessGate` answered by the host). Rewriting would cost weeks and gain little.

**Feed blocks into A2UI?** Yes, and this is the useful direction. A "Whity catalog" in A2UI's catalog format, generated from `BlockContract.php`, would describe the same 33 types. The server would translate a blocks tree into A2UI messages. Any A2UI renderer that registers Whity widgets for those types could then draw plugin screens. That matters most for Flutter: `Plugin-SDK-Coverage.md` lists "rendering plugin blocks in the desktop or Flutter apps" as a gap. `a2ui_flutter` gives the parsing, binding and action plumbing. We would still write the Flutter widget for each type.

## 4. Agents: proposals in the Inbox and in chat

Today a proposal is always the fixed `ActionProposal` card (section 15): one line of what will happen, two examples then "Show all", before → after, the permissions, the undo window, and Approve / Edit / Decline. The diff comes from a server dry run (`ProposalDiffRegistry`), not from the agent. That is deliberate. The person sees what Whity will do, not what the agent says it will do.

**Where A2UI fits.** The weak spot of the fixed card is **Edit**. Today it can only reopen the arguments. An agent could attach an A2UI surface to a proposal: a short, prefilled form, drawn only from the Whity catalog, that the person changes before approving. "Send this quote to Example Bakery: 3 items, $312.00, valid 14 days", with the items, price and days editable in place. The surface's data model becomes the proposal's arguments. The server checks them against the operation's own schema again, reruns the dry run, and the fixed card shows the new before → after.

**What must stay fixed** (proposed rule AU.3):

- The proposal frame stays host-drawn and can't be drawn by an agent: the `ActorChip`, the summary line, the dry-run diff, the permissions, the undo window, and Approve / Decline. An agent can only fill the body.
- An agent surface sits inside the dashed sheet with an "Agent-drawn" label, so it can never look like Whity's own screens.
- A press inside the surface never runs anything. It edits the data model or sends an A2UI action. Whity turns the action into `call_operation` under the agent's grant, and the usual rules apply: Off refused, Ask first becomes a new proposal, Allowed runs as "agent for person".
- Only the person the agent works for sees the surface's Approve, as in CH chat rows today.

**Inbox ("the action room", section 37).** The Inbox shows proposals as items, so the same surface shows there. No second design is needed.

**Chat.** In chat (CH.1/CH.2), an agent reply could carry a read-only surface: a table of results, a stat, a list to pick from. Picking is an A2UI action, which comes back to the agent as its next turn, the way the travel sample works. This is the closest copy of the sample and the least risky use. It shows things and changes nothing.

**What A2UI doesn't give us.** The spec has no "confirm before sending" on actions. The only related checks are input validation (`@checks`) and `requiresUserActivation`. Whity has to add confirmation itself, which it already does, because approval lives in proposals, not in buttons.

## 5. MCP

A2UI has a guide for MCP. A tool result carries an embedded resource with type `application/a2ui+json`, and a button can call a tool back. Whity's MCP server (`MCP-TOOL-SURFACE.md`) could return a surface with `call_operation` results, such as a proposal as a small card in Claude or ChatGPT.

**Not now.** Few MCP hosts draw A2UI today. The hosts that draw anything mostly use MCP Apps (sandboxed HTML iframes). Our MCP results already carry `proposal: {id, status, summary}`, and every host can show that. Revisit when a host Whity users rely on draws A2UI.

## 6. One description, three screens

| Screen | Today | With a Whity catalog on A2UI |
| --- | --- | --- |
| Web (Next.js) | blocks renderer | `@a2ui/react` with Whity's own components registered per type |
| Whity Desktop (Tauri + React) | its own blocks renderer, kept in step with web by shared tests | the same React package as web, so one less copy |
| Flutter app (section 26, not started) | nothing | `a2ui_flutter` with Whity widgets per type, themed by `flutter/whity_tokens` |

So yes, one description can draw on all three. The catch: A2UI gives the plumbing, not the widgets. The 33 Whity widgets still have to be written in Flutter, and the web ones adapted. The win is that the plumbing (parsing, binding, partial updates, actions, version negotiation) is shared and maintained upstream.

**Arabic.** None of the A2UI pages read mentions right-to-left. Both React and Flutter take direction from the host app, so it should work, but nobody has shown it. A pilot must check Arabic layouts first (rule AU.6).

## 7. Security in a multi-tenant app

Agent-drawn UI adds one new risk: **a screen whose author is a model that may have read hostile text.** A customer's message, a form answer or a document can carry instructions. Rules for any pilot:

1. **Catalog only, ours only.** Whity advertises one catalog id (`https://whity.dev/a2ui/catalog/v1`), plus the basic set if we choose. No inline catalogs (`acceptsInlineCatalogs: false`).
2. **Validated on the server, in PHP, before storing or sending**, the same fail-closed way as blocks: JSON Schema per component, the same depth and node limits as blocks (32 / 500), strings as text only. There is no PHP SDK, so this is our code. That's fine, because blocks already work this way.
3. **No outside URLs.** `Image`, `Video` and `AudioPlayer` take only the workspace's own media. Images are a classic way to leak data through a tracking pixel. `openUrl` is either turned off or limited to relative Whity paths.
4. **Tenant fencing.** A surface belongs to one workspace, one agent and one person. It is stored with the proposal (`agent_proposals`) or the chat message, never shared across workspaces. Its data model holds only what that agent could already read under its grant.
5. **Presses are requests, not actions.** See section 4. The host maps every action name to an operation and checks the grant. Unknown action names are dropped and logged.
6. **No spoofing.** The catalog has no component that looks like Approve, Decline or the system frame. Agent surfaces are always labelled and dashed.
7. **No sandboxed HTML.** A2UI can host MCP Apps (agent HTML in a sandboxed iframe). Whity doesn't turn that on. It is a different, much larger risk.
8. **Size and rate limits** per agent, like the MCP call limits.

## 8. Proposed register rows

- **AU.1** A2UI is piloted, not adopted. Plugin blocks stay the plugin format.
- **AU.2** Whity has one A2UI catalog, generated from `BlockContract.php`, so blocks and agent surfaces share one vocabulary.
- **AU.3** Agents may fill a proposal's body with an A2UI surface. The proposal frame (who, summary, dry-run diff, permissions, undo, Approve / Decline) is always host-drawn.
- **AU.4** Every A2UI action goes through `call_operation` and the agent's mode. Nothing runs from a surface directly.
- **AU.5** Surfaces are validated in PHP before storing or sending. No outside URLs, no inline catalogs, no sandboxed HTML.
- **AU.6** Arabic and right-to-left are checked before any surface ships.
- **AU.7** The Flutter app draws plugin screens through `a2ui_flutter` and the Whity catalog, if pilot 2 holds.
- **AU.8** A2UI over MCP waits until a host our users rely on draws it.

## 9. Recommendation, effort and questions

**Recommendation: pilot, in two steps, each with its own go / no-go.**

| Pilot | What | Rough effort | Proves |
| --- | --- | --- | --- |
| **1. Editable proposals (web)** | a Whity catalog with the form inputs, text, key-value and table types; PHP validator; surface stored on `agent_proposals`; `@a2ui/react` in the Inbox and in chat; data model → operation arguments → dry run again | 2–3 weeks | agents can draw useful, safe screens inside the approval rules |
| **2. Plugin screens on Flutter** | full Whity catalog from `BlockContract.php`; blocks → A2UI translation on the server; Flutter widgets for the 33 types on `a2ui_flutter` | 3–4 weeks, most of it Flutter widgets we would need anyway | one description drawn on web, desktop and phone |

**Skip:** replacing blocks, free-form agent dashboards, MCP Apps HTML, and A2UI over MCP for now.

**Maturity.** The repo started in September 2025 and has about 18,000 stars and weekly releases. Spec v1.0 is "Current" (created November 2025, updated June 2026). The SDKs are still pre-1.0 and moving fast: `a2ui-core` went from 0.5 to 0.7 and `a2ui-agent-sdk` from 0.10 to 0.12 within a week, and `a2ui_flutter` is 0.1. The labs sample is "demonstration only". Pin versions and expect breaking changes. That is fine for a pilot and too early to build the core on. **Licence:** Apache 2.0, which fits Whity's open core.

**Questions for the owner:**

1. Pilot 1 (editable proposals) first, or pilot 2 (plugin screens on the phone) first?
2. Should agents be able to show read-only surfaces in chat (results, tables, pick-one lists) in pilot 1, or only proposals?
3. Is "Agent-drawn" the right label for agent surfaces, and does the brand team need to draw the frame?
4. Do we accept a Google-led project as a dependency in core, given it is Apache 2.0 but its SDKs change weekly? Or keep it in a separate package until it reaches 1.0?
5. Should the Flutter app (section 26) start with A2UI as its way to draw plugin screens, or with a hand-built blocks renderer, as web and desktop have?
