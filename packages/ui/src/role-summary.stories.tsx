import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"

import { Avatar } from "./avatar"
import { RoleSummary } from "./role-summary"
import { TenantSwitcher } from "./tenant-switcher"

// WHIT-1175: eight long role names, as in the owner's report.
const EN = [
  "Head of finance and accounts",
  "Branch manager for the north region",
  "Document approver",
  "Inventory controller",
  "Customer service supervisor",
  "Payroll officer",
  "Marketing coordinator",
  "Quality assurance reviewer",
]
const AR = [
  "رئيس قسم المالية والحسابات",
  "مدير فرع المنطقة الشمالية",
  "معتمد المستندات",
  "مراقب المخزون",
  "مشرف خدمة العملاء",
  "موظف الرواتب",
  "منسق التسويق",
  "مراجع ضمان الجودة",
]
const AR_LABELS = {
  more: (count: number) => `+${count} أخرى`,
  separator: "، ",
  all: "كل الأدوار: {roles}",
}

const meta = {
  title: "People/RoleSummary",
  component: RoleSummary,
  tags: ["autodocs"],
  args: { roles: EN },
} satisfies Meta<typeof RoleSummary>

export default meta
type Story = StoryObj<typeof meta>

/** The first role and "+7 more" on one line. */
export const ManyRoles: Story = {
  decorators: [
    (Story) => (
      <div style={{ width: 240 }} className="text-xs text-muted-foreground">
        <Story />
      </div>
    ),
  ],
}

/** One role is just the role. */
export const OneRole: Story = { args: { roles: ["Branch manager"] } }

/** Outside another control: a focusable "+7 more" with the list in a tooltip. */
export const Interactive: Story = {
  args: { interactive: true },
  decorators: [
    (Story) => (
      <div style={{ width: 280 }} className="text-sm text-muted-foreground">
        <Story />
      </div>
    ),
  ],
}

function Surfaces({ roles, labels, name }: { roles: string[]; labels?: typeof AR_LABELS; name: string }) {
  return (
    <div className="grid gap-6 bg-background p-4 text-foreground">
      {/* Sidebar foot (256px sidebar). */}
      <div
        style={{ width: 240 }}
        className="flex min-h-12 items-center gap-3 rounded-lg border border-border px-2 py-2"
      >
        <Avatar name={name} size={32} />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium">{name}</span>
          <RoleSummary roles={roles} labels={labels} className="text-xs text-muted-foreground" />
        </span>
      </div>
      {/* Workspace switcher row. */}
      <div style={{ width: 216 }}>
        <TenantSwitcher
          workspaces={[
            {
              id: "1",
              name: "Example Bakery",
              roles,
              roleBefore: labels ? "المالك" : "Owner",
            },
            { id: "2", name: "Example Clinic", roles: roles.slice(0, 1) },
          ]}
          currentId="1"
          labels={{ roles: labels }}
          onChange={fn()}
        />
      </div>
      {/* Sign-in workspace picker button. */}
      <button
        type="button"
        style={{ width: 340 }}
        className="flex h-10 items-center justify-between gap-3 rounded-control border border-border px-4 text-sm"
      >
        <span className="min-w-0 truncate">Example Bakery</span>
        <RoleSummary roles={roles} labels={labels} className="max-w-[50%] shrink text-xs text-muted-foreground" />
      </button>
      {/* More page profile row on a phone (375px). */}
      <div style={{ width: 343 }} className="flex min-h-16 items-center gap-3 rounded-lg border border-border px-4">
        <Avatar name={name} size={40} />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-base font-medium">{name}</span>
          <RoleSummary
            roles={roles}
            before={labels ? "الملف الشخصي" : "Profile"}
            after="Example Bakery"
            labels={labels}
            className="text-sm text-muted-foreground"
          />
        </span>
      </div>
      {/* Your role › other workspaces. */}
      <div style={{ width: 343 }} className="flex items-center justify-between gap-3 py-2.5 text-sm">
        <span className="shrink-0">Example Clinic</span>
        <RoleSummary roles={roles} labels={labels} interactive className="justify-end text-muted-foreground" />
      </div>
    </div>
  )
}

/** Every place a person's roles sit under their name, in English. */
export const EverySurfaceEnglish: Story = {
  render: () => <Surfaces roles={EN} name="Amro Saleh" />,
}

/** The same in Arabic; switch Direction to RTL. */
export const EverySurfaceArabic: Story = {
  globals: { direction: "rtl" },
  render: () => <Surfaces roles={AR} labels={AR_LABELS} name="عمرو صالح" />,
}
