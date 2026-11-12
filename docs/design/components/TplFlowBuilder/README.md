# Flow builder

A flow top to bottom (FL.1), the route editor (`TplFlowEditor`) grown to cover everything.

- **Kinds of step:** the **trigger** (blue: something is created, changes, is late, crosses a level, or a schedule), a **condition** (amber, with Yes and No branches side by side), **a person decides** (a person, a role, a unit's manager), **an agent acts** (dashed, the robot: within its scope, Ask first actions still come to a person), **wait** (for a reply, a date, a payment), and **done**.
- A **+** on every connector adds a step there. The selected step lifts onto the plane.
- **The step's settings** start with its sentence, then who decides, what they can do (approve, send back, change), and what happens if no one answers (remind, then pass it on) or while they're away (it goes to whoever they handed over to, `TplAway`).
- **Try it** runs the flow on a sample without sending anything; **Runs** lists every run with where it is now; **Publish version 4** applies to new runs, and runs in progress finish on the version they started.
- Tier-3 steps (signing, money above a limit) always ask for Face ID or a passkey.
