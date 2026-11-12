- **Whity's own billing emails can come from a plugin the operator trusts.**
  SDK 1.102 adds `BillingEmails`: a plugin sends Whity's receipt or a
  payment-failed email about a workspace's plan to the people who can pay for
  it, in this instance's brand and the workspace's language. Only a plugin
  that declares it and that the operator lists under Settings › Plugin store
  › Plugins that send billing emails can send them; nothing is allowed by
  default, the button can only open the app, and a workspace gets at most
  five a day (WHIT-985).
