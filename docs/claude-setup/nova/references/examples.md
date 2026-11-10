# Worked examples

Concrete cases that show what the user means by "the best UI/UX". Read one when you face a similar situation; don't copy it blindly.

## Example 1 — Patient step of the order wizard (Medyar nursing)

**Request:** "Can these cards be better?" (patient selection cards, step 1 of 4).

**The cosmetic answer (fine, but second):** the cards were 152px tall with three redundant "selected" signals and a different layout on mobile. Fix: one compact horizontal card (icon circle + name + meta + radio, 72px, border-primary + soft tint when selected; dashed low-emphasis "add new patient"). Saves ~80px and removes a duplicate mobile layout.

**The logic answer (first, and what the user loved):** the screen asked a question whose answer was already known. "Myself" was preselected and the phone was prefilled, yet every user still got a full screen to confirm it.

- Collapse step 1 for returning users into one summary line: "برای سارا محمدی (خودم) · ۰۹۱۲… — [تغییر]" with the primary "ادامه" right below.
- "تغییر" opens the compact cards, sorted by last used, with "آخرین سفارش: …" under each name.
- New users with no saved patients skip the summary and land directly on the add-patient form.
- The 4-step stepper stays (client-approved design); the step just costs zero effort when nothing needs changing.

**Why it works:** smart defaults, progressive disclosure, Hick's law, recognition over recall. The majority path got shorter; the rare path got a clear fork.

**How it was delivered:** audited the live page first (measured heights, counted 14 required fields, tested the empty-submit error flow), then a before/after mockup in project tokens (ARP: 50px fields, 52px buttons, 16/24 radii, no shadows), then asked "collapsed summary" vs "compact cards only" before touching code.

**Other findings from the same audit (use as a pattern for similar screens):**
- Validation showed one generic notice at the top, marked no field, and moved focus to the notice. Fix: mark the field (`is-invalid`), put the message under it, focus it.
- Two selects side by side at 390px crushed the placeholder to three lines. Fix: one column at ≤640px.
- The same sentence ("pay after the service") appeared four times and the service name four times: repetition is noise, remove it.

## Example 2 — When the nurse comes (date + time, Medyar)

**Cosmetic first pass (rejected as too clever):** a "nearest valid time" chip list plus an expandable "other time" panel. The user called it complicated.

**What shipped:** two plain fields. Date: a Jalali datepicker (the project already used `persianDatepicker` in the affiliate plugin: reuse the library and copy its theme with the project's tokens). Time: the wizard's own custom select that lists only windows that can be booked (not started, per *server* time; nurse available in the chosen area), so no "available / unavailable" text is needed. Defaults: the first day that still has a valid window and its first window.

**Lessons:** a simple control the user already knows beats a smart new one; show only valid options instead of explaining invalid ones; take time from the server, not the visitor's clock; when the user says "complicated", cut scope, don't defend the idea.

## Example 3 — Hover turned dark text into dark-on-dark

The theme paints every button `:hover/:focus/:focus-within` with `--primary-200` (#0f3e4f). Components whose own background is transparent or set at low specificity (select trigger, switch, text button) inherited that dark fill under their dark text. Fix: explicit hover/focus background for each such component, higher specificity than the theme's `button:hover`. Found by cloning the `:hover` rules onto a class and reading computed colours (a real mouse is not available in the browser pane).

## Example 4 — Small feedback round (what "compact" meant)

Select text was centred by the button default: set `justify-content: flex-start`. Gender cards carried a subtitle that repeated the name: removed. The "not available here" pill went inline beside the name and shortened. The red availability box lost its 58px icon with glow ring and became a 32px icon with tighter text. The single "continue" button became full width. The "resume draft" banner was removed. Delivered by FTP with the repo script, not committed.

## Example 5 — Review step feedback round (what each comment turned out to be)

- "Dropdown stays open after choosing" → a click forwarded by the wrapping `<label>` re-opened it. Found with a stack trace, not by reading code. Fixed once for all selects.
- "Stepper lines and dots overlap, progress unclear" → legacy `!important` boxes around each step plus dotted connectors. Solid track, done/current/pending states, same look reused for the order timeline.
- "Review has a UX problem!!!" (no cause given) → diagnosed: edit jumps to the top of step 1, rows misaligned, pay note far from the button, giant rows on phones. Fixed all four and said so; the user later also wanted service details as their own step again, so a merge the user asked for can be reversed: renumber everything (rule 17).
- "Elements not aligned" → icon top-aligned while text and button were centred; one centre line fixed it.
- "Loading shows over the text" → fade the label, then the spinner (rule 19).
- "Narrower qty, wider select" → grid `1fr auto`, qty 136px (112px on phones).
- "Selected button needn't be bolder" → remove weight change (it also beat the `!important` base rule, so the override needed `!important`).
