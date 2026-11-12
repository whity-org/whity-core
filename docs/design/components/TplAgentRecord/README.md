# Agent (A21)

The agent's record page (AI.3): header with the robot avatar, name, status, who it works for, today's count and since when; **See activity** and **Pause** as buttons (A.2); ⋯ holds Delete agent.

- **Overview:** What it does (plain sentences, including what it never does), Today (its activity with the actor chip "for Amro"), On its own (counts and examples of Allowed, Asks first, Never), Where it works (apps, channels, languages).
- **Instructions:** `TplAgentInstructions`.
- **What it may do:** the ScopeGrant list (Off, Ask first, Allowed per action, in plain words), as on `TplAgentScope`. An agent never has more access than the person it works for; actions that person lacks are shown with the reason.
- **Activity:** the agent's activity, filterable, with Undo where the window is open.

Arabic card: `TplAgentRecordAr`.
