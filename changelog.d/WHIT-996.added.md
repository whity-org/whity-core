- **Customers can invite their own team, when the workspace allows it.**
  Settings › Sign-up has a new switch, off by default. With it on, a customer
  can invite colleagues by email. They join with the inviter's own role, as
  customers and never as staff. The answer never shows who is already in the
  workspace, and a customer's invitation never replaces anyone else's. Each
  customer can send 10 an hour, and every invitation is audited. SDK 1.105
  adds `membership.customer_inviting`, so a plugin can refuse an invitation
  or attach a note that comes back when it is accepted. Invitations are
  emailed, so this needs the instance's mail set up (#1872, WHIT-996).
