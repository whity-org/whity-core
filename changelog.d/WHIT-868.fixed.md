- **People are no longer signed out at random when a screen loads.** After the
  15-minute access token expired, a screen's parallel requests each renewed the
  session, and the extras were refused and signed the person out. The browser now
  renews once, across tabs too. A throttled or failing refresh no longer signs
  anyone out either (WHIT-868).
