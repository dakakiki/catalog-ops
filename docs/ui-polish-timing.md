# Interface polish: now, during M6, or after? — decision memo

*Draft for review. Written after M5 shipped (formulas, scheduling, notifications,
admin UI), before M6 (licensing, auto-update, multisite, onboarding, docs,
translations, MySQL 8.0 verification).*

The admin app was built "functionality-first, visual polish comes later" (it says
so in the code). The question now: **when do we make it polished?**

---

## The insight that reframes the question

**M6's definition of done is itself a UX bar.** From CONTEXT §4, M6 is done when
*"someone who has never seen us performs their first operation without
explanation."* You cannot pass that bar with a confusing interface. So the
**usability** part of "polish" is not a separate task you can schedule before or
after M6 — it *is* part of M6.

What *is* separable is **cosmetic branding** (final colours, logo, typography,
marketing-grade visuals). And that is **blocked on the product name** (CONTEXT §7,
decision #7 — still open, due before M6). Branding the UI before the name is
decided risks doing it twice.

So the real choice is narrower than "polish now vs later." It is: *how much
usability work do we pull forward before M6 formally starts, and when do we do the
brand cosmetics?*

Two facts to hold while reading the options:

- **Launch timeline (§4):** week 14 private beta (5–10 technical agencies), week
  18 free version on WordPress.org, week 22 commercial launch. Rough-but-clear is
  tolerable for the technical beta; brand polish matters most for wp.org and the
  commercial launch.
- **The name is undecided.** Cosmetic/brand work done now may be reworked.

---

## Option A — Dedicated polish pass **now**, before M6

Do a full UI/UX + visual pass now, then build M6 on the polished base.

**Pros**
- The code is fresh in mind; fastest to navigate right now.
- M6's onboarding is built on a stable, good-looking base instead of a moving one.
- Clean separation of concerns: finish the UI, then do go-to-market plumbing.
- Better first impression if any beta feedback happens early.

**Cons**
- **Rework risk:** the name/brand isn't chosen, so any cosmetic/visual work may be
  redone once branding lands.
- **Duplication:** onboarding and usability polish overlap M6's own DoD, so part of
  this pass would be done again during M6.
- Delays the M6 plumbing (licensing, multisite) that gates revenue.
- Polishing before real user feedback risks polishing the wrong things.

**Best if:** we want a showcase-quality UI for the beta and are willing to accept
some rework, and the name is about to be decided anyway.

---

## Option B — Fold usability into M6, defer cosmetics to launch **(recommended)**

Treat structural/usability clarity as part of M6 (it's the M6 DoD), and do
brand/cosmetic work as a step *inside* M6, after the name is chosen.

**Pros**
- Aligns with reality: M6's DoD is a usability bar, so this is where the work
  belongs anyway.
- **No rework:** the name is decided first, then the UI is branded once.
- One coherent pass; real onboarding requirements drive the UX instead of guesses.
- Keeps momentum toward the revenue-gating M6 plumbing.

**Cons**
- The UI stays rough through the early, plumbing-heavy part of M6.
- Requires discipline to actually do the usability part of M6 well rather than
  treating M6 as "just licensing + multisite."

**Best if:** we trust M6 to carry the usability work (it must, by its DoD) and want
to avoid rework against an undecided brand. **This is the recommendation.**

---

## Option C — Defer **all** UI polish to after M6

Ship all of M6's functionality on the current rough UI, polish once at the very
end before commercial launch.

**Pros**
- Maximum focus on M6 functionality first.
- Everything that needs styling (licensing screens, multisite, onboarding) exists
  before we polish, so nothing is styled twice.

**Cons**
- **Contradicts the M6 DoD** — a stranger cannot complete a first operation on a
  rough UI unassisted, so M6 can't truly be "done."
- Poor beta (week 14) impression; weaker, noisier feedback.
- Polish lands under launch pressure (week 18–22), the worst time for it.
- "Temporary rough UI" has a way of becoming permanent.

**Best if:** the beta is pushed later and we're confident we can absorb a big
polish pass right before launch.

---

## Option D — Thin slice of targeted fixes **now**, rest during M6

Do only the cheapest, highest-value fixes now — the "functionality-first rough"
worst offenders — and leave the real pass to M6.

Candidate thin-slice items (small, non-cosmetic, no brand dependency):
- Clearer labels and helper text where the current copy is confusing.
- Proper empty states and loading states.
- The mandatory **backup reminder** on a first destructive operation (CONTEXT §9
  lists this as a core risk mitigation).
- Make the safety confirmations robust (the browser test showed `window.confirm`
  is fragile — a real in-app confirm/modal is both safer and testable).
- Consistent error surfacing.

**Pros**
- Cheap, high value; improves the beta without preempting branding.
- No rework — none of it depends on the name.
- De-risks the most embarrassing rough edges immediately.

**Cons**
- Still not "polished"; it's a floor, not a finish.
- Needs a clear line so it doesn't quietly grow into Option A.

**Best if:** we want a quick quality lift now without committing to a full pass —
pairs naturally with Option B.

---

## Recommendation

**Option B, with a thin slice of D now.**

1. **Now (thin slice, ~small):** the backup reminder, a real confirm modal
   (replacing `window.confirm`), empty/loading states, and the worst confusing
   copy. All name-independent, all pure upside.
2. **Decide the product name** (§7 #7) — it gates all cosmetic work and is due
   before M6 regardless.
3. **During M6:** do the usability/onboarding work as the core of M6 (it *is* the
   M6 DoD), and the brand/cosmetic pass as a step after the name is set.
4. **Avoid** a big standalone polish pass now (Option A): it spends effort on
   cosmetics against an undecided brand and duplicates M6's onboarding work.

### One-line summary

Usability is M6 (by its own DoD); cosmetics are gated on the name; so don't run a
separate polish phase now — take the cheap fixes now, decide the name, and let M6
carry the rest.
