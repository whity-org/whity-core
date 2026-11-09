import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconQrcode } from "@tabler/icons-react"

import { PaperEyebrow, PaperGround, PaperHeadline, PaperSheet } from "./paper-sheet"
import { Lockup } from "./lockup"

/**
 * Sheets on paper (docs/design/10-patterns.md): white sheets on the grey
 * ground, one on its plane under the one thing that matters.
 */
const meta = {
  title: "Brand/Sheets on paper",
  component: PaperSheet,
  tags: ["autodocs"],
  parameters: { layout: "fullscreen" },
} satisfies Meta<typeof PaperSheet>

export default meta
type Story = StoryObj<typeof meta>

export const Sheet: Story = {
  render: () => (
    <PaperGround className="p-10">
      <PaperSheet className="max-w-md space-y-3 p-8">
        <PaperEyebrow>Example Bakery · Documents</PaperEyebrow>
        <PaperHeadline>Every paper you issue can be checked.</PaperHeadline>
        <p className="text-muted-foreground">Scan the code on a printed document to see that it is genuine.</p>
      </PaperSheet>
    </PaperGround>
  ),
}

export const OnThePlane: Story = {
  name: "On the plane (clickable)",
  render: () => (
    <PaperGround className="p-10">
      <PaperSheet as="button" type="button" plane className="flex max-w-sm items-center gap-4 p-5">
        <span className="grid size-11 shrink-0 place-items-center rounded-control-lg bg-primary-tint text-primary-tint-foreground">
          <IconQrcode className="size-6" aria-hidden />
        </span>
        <span className="space-y-0.5">
          <span className="block font-semibold">Verify a document</span>
          <span className="block text-sm text-muted-foreground">Scan the QR code on a printed page.</span>
        </span>
      </PaperSheet>
    </PaperGround>
  ),
}

export const NotYet: Story = {
  name: "Dashed (not yet)",
  render: () => (
    <PaperGround className="p-10">
      <PaperSheet dashed className="max-w-sm p-5 text-muted-foreground">
        An optional step that has not happened yet.
      </PaperSheet>
    </PaperGround>
  ),
}

export const Composition: Story = {
  render: () => (
    <PaperGround className="flex min-h-[32rem] flex-col justify-between gap-10 p-10">
      <div className="max-w-md space-y-8">
        <PaperSheet className="space-y-3 p-8">
          <PaperEyebrow>Open source · White-label · MCP</PaperEyebrow>
          <PaperHeadline>Agents propose. Your team decides.</PaperHeadline>
          <p className="text-muted-foreground">One sheet carries the message; the plane sits under the one thing that matters.</p>
        </PaperSheet>
        <PaperSheet as="button" type="button" plane className="ml-6 block w-[calc(100%-1.5rem)] p-5 font-semibold">
          Verify a document
        </PaperSheet>
      </div>
      <Lockup size={28} />
    </PaperGround>
  ),
}

export const LockupSizes: Story = {
  name: "Lockup",
  render: () => (
    <PaperGround className="flex flex-col items-start gap-6 p-10">
      <Lockup size={20} />
      <Lockup size={32} />
      <Lockup size={48} />
    </PaperGround>
  ),
}
