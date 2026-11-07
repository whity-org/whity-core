- **The editor no longer opens with a table toolbar over the letterhead.** The
  block toolbar waits until you click into the document (WHIT-999).
- **The letterhead prints the workspace's name, not "Whity",** when no company
  name or site name of its own is set (WHIT-999).
- **"Last edited by at 18:51" is fixed.** The status bar reads "Last edited at
  21:51" until it knows who, in your own time zone rather than UTC (WHIT-999).
- **Tables in the editor stopped redrawing themselves nonstop.** A table with
  end-aligned columns rebuilt its cells thousands of times a second, which made
  a field chip inside it hard to click and kept the page busy (WHIT-999).
