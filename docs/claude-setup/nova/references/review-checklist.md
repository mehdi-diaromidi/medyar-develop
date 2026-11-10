# UI/UX + Frontend Review Checklist

Use this for a dedicated UI audit or before shipping a large new screen. Report findings ranked by user impact (blocks a task → causes errors/confusion → slows down → polish). For each finding: what's wrong, who it hurts, the fix.

## Contents
1. Task & flow
2. Hierarchy & layout
3. Content & copy
4. States
5. Forms
6. Visual consistency
7. Accessibility
8. Responsive & RTL
9. Performance
10. Code quality

## 1. Task & flow
- [ ] The primary task is obvious within ~3 seconds of landing.
- [ ] The number of steps/clicks to finish the primary task is minimal; nothing asked that could be inferred or defaulted.
- [ ] The user always knows where they are (title, breadcrumb, active nav) and how to go back.
- [ ] Irreversible actions are confirmed or undoable; destructive buttons are visually distinct and not next to the primary action.
- [ ] Nothing the user typed can be lost by navigation, refresh, or a failed request.

## 2. Hierarchy & layout
- [ ] One clear focal point; one primary button per view.
- [ ] Related items grouped; unrelated items separated by space, not just lines.
- [ ] Consistent alignment to a grid; consistent spacing from the scale.
- [ ] Dense data (tables, lists) is scannable: aligned columns, numbers right/end-aligned with tabular figures, clear row separation.

## 3. Content & copy
- [ ] Labels and buttons describe the outcome ("ثبت درخواست"), not the mechanism ("Submit").
- [ ] Error messages say what happened and what to do next, in plain language.
- [ ] Empty states explain what will appear here and offer the first action.
- [ ] Terminology is consistent across screens (same thing, same word).

## 4. States
- [ ] Loading (no layout shift; skeleton matches final layout), empty, error, success, partial data.
- [ ] Long text, missing images, zero/one/many items, very large numbers handled.
- [ ] Hover, active, focus-visible, selected, disabled (with reason) all styled.
- [ ] Double-submit prevented; buttons show progress while working.

## 5. Forms
- [ ] Visible labels (placeholder is not a label); required/optional clearly marked (mark the minority).
- [ ] Correct `type`, `inputmode`, `autocomplete`; numeric keyboards for phone/code fields.
- [ ] Inline validation at the right moment (on blur / on submit, not on every keystroke for a half-typed value).
- [ ] Errors next to the field, linked with `aria-describedby`; focus moves to the first error on submit.
- [ ] Logical field order; related fields side-by-side only when it helps (and stacked on mobile).

## 6. Visual consistency
- [ ] Only design tokens / existing variables used for color, spacing, radius, type, shadow.
- [ ] Icons share one style and are optically aligned with text.
- [ ] Semantic colors used only for meaning, never as decoration; meaning never conveyed by color alone.
- [ ] Matches sibling screens in the product (same card style, button sizes, page header pattern).

## 7. Accessibility
- [ ] Semantic elements; heading levels in order; landmarks present.
- [ ] Contrast ≥ 4.5:1 for text, ≥ 3:1 for large text and UI controls/borders that matter.
- [ ] Fully keyboard operable; visible focus; modals trap and restore focus and close on `Esc`.
- [ ] Touch targets ≥ 44×44px.
- [ ] Images have meaningful `alt` (or `alt=""` if decorative); icon-only buttons have accessible names.
- [ ] `prefers-reduced-motion` respected; text scales with `rem`.

## 8. Responsive & RTL
- [ ] Works at 320px without horizontal scroll; comfortable at 375px, 768px, 1280px+.
- [ ] Logical properties used (inline-start/end), not left/right.
- [ ] Directional icons mirrored in RTL; LTR fragments (phones, codes, Latin names) isolated.
- [ ] Persian line-height and font sizes comfortable; digit style and date format consistent.

## 9. Performance
- [ ] Images sized, compressed, lazy-loaded below the fold, with dimensions/aspect-ratio reserved.
- [ ] No unnecessary libraries; JS only where HTML/CSS can't do the job.
- [ ] Animations use `transform`/`opacity` only.
- [ ] No layout thrashing in loops; scroll/resize/input handlers debounced or passive where appropriate.
- [ ] CSS/JS loaded only on pages that need them.

## 10. Code quality
- [ ] Follows project naming conventions and file structure; change is surgical.
- [ ] No duplicated styles/components that already exist elsewhere.
- [ ] No `!important` or deep selectors without a documented reason.
- [ ] Output escaped in templates; no untrusted `innerHTML`.
- [ ] Network failures handled with visible feedback; no silent catch.
- [ ] No dead code, commented-out blocks, or leftover debug logs.
