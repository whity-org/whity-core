# Forms

Version 2.6: building forms, filling them in, the public form and responses (decisions F.1–F.4). Cards: "Templates · Forms".

## Building

A form is a record (P.2) with three tabs: **Questions**, **Responses**, **Settings**. The Questions tab is the builder (F.1): the form as people will see it, on the grey ground, with the selected question's settings in a side panel.

- Sections carry a bar in the main colour; questions are cards with their number, title, required star, help, type and a preview of the answer.
- Selecting a question rings it in the main colour and shows move, duplicate and delete.
- **Add question** between cards offers the ten kinds the platform has: short text, long text, number, date, choose one, choose several, checkbox, file, person, unit.
- The settings panel holds type, wording, help, options, Required, **Show this question** (Always or Only if …) and **Save the answer to** a field on a record. Questions with a condition show an "Only if …" pill on the canvas.
- Preview and **Publish** (the main button) sit in the header.

The Form builder list shows every form with its status (Draft, Open, Closed), who can fill it in words, responses and the last change.

## Filling in

Short forms are one page. A form with sections shows them as steps with the Stepper, and the last step is Review (F.2). What the workspace already knows comes prefilled as a summary from the person's profile. The draft saves as people go, on the server, and the Forms page lists it under Continue where you left off. The description names who receives the form; the decision arrives in the Inbox. People see their own submissions, and where each is, in **My submissions**.

## The public form

At `/f/[slug]`, the workspace's brand leads: its logo above the card, its colour on the card's edge, the main button and selections (F.3). A small **Built on Whity** sits in the footer with Privacy and the language switch. No sign-in, no app shell. Sent, closed and not available each give the visitor a way forward: a reference and when to expect a reply, how to reach the workspace, or to check the link.

## Responses

The Responses tab (F.4) starts with a summary for each choice question, responses per week and where responses are in their routing, then a table. A response opens as a record with its answers, files and routing timeline. Export and Close the form sit in ⋯.

## Fixed from the built app

All form text is translatable (the built forms were hardcoded English); choices are Whity radio buttons and selects instead of native dropdowns; the button names what happens ("Send application"), not "Submit".

The 2.6 Arabic card is the form builder (`TplFormBuilderAr`).
