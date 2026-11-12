- **Plugins can let a workspace's customers act on their own records.**
  SDK 1.104 adds `PluginCustomerPermissionsInterface`: a plugin lists those of
  its own permissions that reach only the caller's own records, such as a
  clinic adding its own patients or uploading its own scans. Customers then
  keep those permissions, and Settings › Sign-up can offer a role made of
  them, which the workspace admin still chooses. Core and admin permissions,
  another plugin's permissions and resources two plugins share never count,
  and disabling or uninstalling the plugin withdraws them at once (#1872,
  WHIT-995).
