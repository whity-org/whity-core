# Booking page

The public page a tenant's customers book from (AP.1), in the tenant's brand. Its address is the tenant's (e.g. `examplebakery.jo/book`), and the same flow is in the customer app.

1. **Service:** name, length, price and who it's with.
2. **Day:** the month with bookable days marked; days with no time left are plain.
3. **Time:** open times for that day, in mono, 44px tall.
4. The summary bar repeats the choice in one sentence; **Book it** asks for a name and mobile number (or signs in with Google or Apple, creating a Whity ID in one step, M.31), then confirms by WhatsApp.

Services that need approval send a request instead (shown on the staff calendar). Cancelling or moving is a link in the confirmation, up to the tenant's cut-off (24 hours by default).
