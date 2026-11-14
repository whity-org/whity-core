import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { SavedBlock, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import { EDITOR_LABELS } from "./labels"
import { SAMPLE_DOCUMENT } from "./sample"
import { SaveBlockDialog } from "./save-block-dialog"
import type { EditorServices } from "./services"

/**
 * Editor M7, the saved blocks half of the EditorCustomBlocks card: a synced
 * block in a document with its label ("Bank details · Synced · used in 12
 * documents", Detach), the / menu's Saved blocks group, and Save as block.
 */
const meta = {
  title: "Editor/Saved blocks",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const t = (text: string) => ({ type: "text" as const, text })

const BANK: SavedBlock = {
  id: "5",
  name: "Bank details",
  mode: "synced",
  sharing: "workspace",
  owner: { kind: "person", id: "1", name: "Amro Saleh" },
  blocks: [
    { id: "s1", type: "heading", level: 3, content: [t("Bank details")] },
    { id: "s2", type: "callout", kind: "note", content: [t("Example Bank, Mecca Street branch · IBAN JO71 EXBK 0000 0000 0000 1234 5678 90 · SWIFT EXBKJOAM")] },
  ],
  perDocumentParts: [],
  version: 3,
  usedIn: 12,
}
const TERMS: SavedBlock = {
  ...BANK,
  id: "6",
  name: "Payment terms",
  mode: "copy",
  sharing: "me",
  blocks: [{ id: "c1", type: "paragraph", content: [t("Please pay within 30 days of the invoice date.")] }],
  usedIn: 0,
}

const SIGN: SavedBlock = {
  ...BANK,
  id: "7",
  name: "Signature line",
  blocks: [
    {
      id: "g1",
      type: "paragraph",
      content: [t("Signed by "), { type: "text", text: "Amro Saleh", marks: [{ type: "perDocument", key: "pd_signer01" }] }, t(" on "), { type: "text", text: "12 November 2026", marks: [{ type: "perDocument", key: "pd_signdate" }] }],
    },
  ],
  perDocumentParts: [
    { key: "pd_signer01", label: { en: "Signer's name", ar: "اسم الموقّع" }, kind: "text" },
    { key: "pd_signdate", label: { en: "Date", ar: "التاريخ" }, kind: "text" },
  ],
  usedIn: 4,
}

const SERVICES: EditorServices = {
  savedBlocks: [BANK, TERMS, SIGN],
  saveBlock: async (b) => ({ ...b, id: "9", owner: BANK.owner, version: 1, usedIn: 0 }),
  canShareSavedBlocks: true,
}

const DOC: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  title: "Invoice letter",
  blocks: [
    { id: "p1", type: "paragraph", content: [t("Thank you for your order from Example Bakery. Please transfer the amount due to:")] },
    { id: "r1", type: "savedBlock", savedBlockId: "5", version: 3, overrides: {} },
    { id: "p2", type: "paragraph", content: [t("Questions? Reply to this letter or call us.")] },
    { id: "r2", type: "savedBlock", savedBlockId: "7", version: 1, overrides: { "g1#0": [t("Awss Haddad")] } },
  ],
}

function Editor({ lang }: { lang?: "ar" }) {
  const [doc, setDoc] = React.useState<WhityDocument>(
    lang === "ar"
      ? { ...DOC, lang: "ar", title: "رسالة الفاتورة", blocks: [{ id: "p1", type: "paragraph", content: [t("شكرًا لطلبك من Example Bakery. يرجى تحويل المبلغ المستحق إلى:")] }, DOC.blocks[1]] }
      : DOC
  )
  return (
    <div dir={lang === "ar" ? "rtl" : undefined} lang={lang} className="h-screen">
      <DocEditor document={doc} onChange={setDoc} canEdit services={SERVICES} />
    </div>
  )
}

/** Hover or select the synced block to see its label and Detach; type / to see the Saved blocks group. */
export const SyncedInADocument: Story = { render: () => <Editor /> }

export const Arabic: Story = { render: () => <Editor lang="ar" /> }

export const SaveAsBlock: Story = {
  render: () => <SaveBlockDialog open onOpenChange={() => undefined} blockCount={2} canShare onSave={async () => undefined} labels={EDITOR_LABELS} />,
}
