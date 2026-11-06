- **Workspaces can let customers sign up.** In Settings, Sign-up, a workspace's own admins choose
  Off (the default), Approval required or Open, the role people get when they join, and optionally
  which email domains may sign up. Only roles with no admin permissions can be chosen, and the role
  is checked again at every sign-up. People confirm their email first, the form is protected by the
  bot check, and the request never reveals whether an address already has an account. Customers get
  a membership of kind `customer`, which can never hold admin permissions whatever its role. Joining
  never creates a workspace; platform sign-up is unchanged. Plugins get `membership.customer_joined`
  (WHIT-915, SDK 1.73, migration 222).
