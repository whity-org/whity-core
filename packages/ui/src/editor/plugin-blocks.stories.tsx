import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import { SAMPLE_DOCUMENT } from "./sample"
import type { EditorServices, PluginBlocks } from "./services"

/**
 * Editor M7, the plugin half of the EditorCustomBlocks card: a Verified
 * plugin's block drawn by Whity (hover or select it for its source label and
 * Edit), and a block whose plugin is off (E.19). In the app, the view is
 * drawn by Whity's screen renderer; here a stand-in draws its key/value rows.
 */
const meta = {
  title: "Editor/Plugin blocks",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const L = (en: string, ar = en) => ({ en, ar })

const BLOCKS: PluginBlocks = {
  plugins: [
    {
      plugin: "DeliveryTracker",
      label: L("Delivery Tracker", "متتبع التوصيل"),
      verified: true,
      firstParty: false,
      blocks: [
        {
          name: "DeliveryTracker/delivery_status",
          blockType: "delivery_status",
          label: L("Delivery status", "حالة التوصيل"),
          description: L("Where an order is", "أين الطلب"),
          icon: "package",
          keywords: [],
          fields: [
            { key: "order", label: L("Order", "الطلب"), type: "text", required: true },
            { key: "customer", label: L("Customer", "العميل"), type: "text" },
            { key: "stage", label: L("Stage", "المرحلة"), type: "select", options: [{ value: "packed", label: L("Packed", "مُعبأ") }, { value: "out", label: L("Out for delivery", "في الطريق") }] },
          ],
          view: [{ type: "keyValue", items: [{ label: "Order", value: "{{data.order}}" }, { label: "Customer", value: "{{data.customer}}" }, { label: "Stage", value: "{{data.stage}}" }] }],
          print: [{ type: "keyValue", items: [{ label: "Order", value: "{{data.order}}" }] }],
          allowedIn: ["note", "wiki", "template"],
        },
      ],
    },
  ],
  off: [{ plugin: "RouteMaps", label: L("Route Maps", "خرائط المسار") }],
  canEnable: true,
}

/** A stand-in for Whity's screen renderer: key/value rows. */
function KeyValues({ view }: { view: unknown[] }) {
  const items = (view as { items?: { label: string; value: string }[] }[]).flatMap((n) => n.items ?? [])
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 rounded-card border border-border bg-card px-4 py-3 text-sm">
      {items.map((i) => (
        <React.Fragment key={i.label}>
          <dt className="text-muted-foreground">{i.label}</dt>
          <dd className="font-medium">{i.value}</dd>
        </React.Fragment>
      ))}
    </dl>
  )
}

const SERVICES: EditorServices = {
  pluginBlocks: BLOCKS,
  renderPluginView: (view) => <KeyValues view={view} />,
  enablePlugin: async () => undefined,
}

const DOC: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  title: "Order handover",
  blocks: [
    { id: "p1", type: "paragraph", content: [{ type: "text", text: "Where Example Clinic's order is:" }] },
    { id: "d1", type: "plugin", plugin: "DeliveryTracker", blockType: "delivery_status", schemaVersion: 1, data: { order: "SO-2207", customer: "Example Clinic", stage: "out" } },
    { id: "r1", type: "plugin", plugin: "RouteMaps", blockType: "route", schemaVersion: 1, data: {} },
  ],
}

function Editor() {
  const [doc, setDoc] = React.useState(DOC)
  return (
    <div className="h-screen">
      <DocEditor document={doc} onChange={setDoc} canEdit services={SERVICES} />
    </div>
  )
}

export const InADocument: Story = { render: () => <Editor /> }
