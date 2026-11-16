# Mobile onboarding: your picture

Step 3 of 5 in creating a Whity ID (M.34), right after the email is confirmed and before Protect your account. Every person gets a picture they like in a few seconds, with nothing to upload if they don't want to.

1. **Pick a pattern** (M.35, M.36): a 168px preview of the pattern made from their Whity ID, "Made from your name. Shuffle or tap another one.", a row of six alternatives (48px, the chosen one ringed), and **Shuffle** and **Use a photo** side by side. All of it sits in the lower half, under the thumb; **Continue** at the bottom.
2. **Or use a photo** (M.38): a bottom sheet with **Your Google picture** (only when they signed up with Google; Apple shares no picture), **Take a photo**, **Choose from your photos**, and **Keep my pattern**.
3. **Round crop:** a dark screen with the photo under a 300px circle. Drag to move, pinch to zoom, or use the **zoom slider** so one thumb is enough; a **Turn** button rotates 90°. "How it'll look" shows it at 48, 32 and 24px. **Use this photo** at the bottom.
4. **How it shows everywhere:** the same pattern at 64 to 24px, and a People list mixing patterns, a photo and an agent.

## The patterns (`W.avatarPattern(seed, hue)`, `Avatar pattern=… hue=…`)

- Built only from the mark's own pieces (M.35): the white **sheet** with its dark offset, the **platform** bar, the **dashed sheet** (an agent's "not yet", used here as a shape only), a dot, and a diagonal band. Four layouts, a slight rotation, positions and sizes all come from the seed; at most three shapes, so each stays readable at 24px.
- **Seed:** the Whity ID (shuffling stores a number with it). The same person looks the same on every screen and device.
- **Colours** (M.37): from the installation's brand hue (`W.brandHue(hex)`): a pale background near that hue, the brand colour, a dark of it (the mark's offset colour), and one accent hue. Lightness is fixed so contrast always holds. The colours come from the installation where the Whity ID was **made** and stay the same everywhere afterwards.
- **People only.** Agents keep the robot on the tint, the system keeps the mark, so a pattern never looks like an agent.
- Removing a photo returns to the pattern. Changed later from More › Profile › Picture (the same screens).
- Customers joining a tenant with Google (M.31) get their Google picture straight away, without this step; Apple and email sign-ups get their pattern and can change it later.
