# CONTENT-TODO — what still needs a human before this site can go live

Everything below is a **deliberate placeholder**, not an oversight. The site is
functionally complete and all tests pass; these are the items the design brief
lists as blockers, plus what surfaced while building.

Nothing here blocks development of the app itself — the marketing site can ship
the moment these are resolved.

---

## 1. Product name, wordmark and logo — BLOCKER

**Status:** placeholder in use.

The product name has not been chosen, which is what blocks real logo/wordmark
work. The site currently renders a text-only wordmark reading
**"Compliance Suite"**, set in `trust-navy` in the Latin typeface.

- Lives in one component: [`resources/views/components/wordmark.blade.php`](../resources/views/components/wordmark.blade.php)
- Every header, footer and nav reference goes through `<x-wordmark />`
- **Swapping in the real logo is a one-file change** — no site-wide find-and-replace

Also update, once the name is settled:

| Where | What |
|---|---|
| `lang/en/marketing.php` → `meta.title`, `story.signature` | Product name in the page title and founder signature |
| `lang/ar/marketing.php` → same keys | Same, Arabic side |
| `lang/*/marketing.php` → `footer.rights` | Currently says "Pre-launch. Product name and branding are not final." |

---

## 2. Arabic copy — needs native Lebanese business-copy review — BLOCKER

**Status:** best-effort draft. **Do not treat as launch-ready.**

The design brief is explicit that *translated* marketing copy undermines the
"built for us" positioning — which is the entire pitch. The Arabic in
[`lang/ar/marketing.php`](../lang/ar/marketing.php) was written alongside the
English rather than translated word-for-word from it, and it is professional
MSA-leaning business Arabic. It is still not a substitute for a native Lebanese
speaker who writes business copy.

Specific things to put in front of a reviewer:

- **Register.** Currently professional MSA with Lebanese business vocabulary
  (`الـ TVA`, `الضمان`, `تعويض نهاية الخدمة`). Confirm this is the right register
  for an owner-operator audience, versus something more colloquial.
- **Numerals.** Prices use Western digits (`19$`, not `١٩$`), which is how
  Lebanese business writing normally prices things. Confirm.
- **`الـ TVA` vs `ضريبة القيمة المضافة`.** The draft uses the French-derived form
  throughout because that is what people say. Confirm it reads right in writing.
- **Month names.** `آذار` / `شباط` (Levantine) rather than `مارس` / `فبراير`
  (Gulf/Egyptian). Confirm.
- **Founder story** (`story.body`) — the one section where a translated voice
  would be most obvious. Probably worth rewriting natively from scratch rather
  than editing.
- **Currency phrasing** for LBP/USD dual-currency copy.

---

## 3. Photography — BLOCKER

**Status:** marked placeholder slots. No stock photography, no illustration.

The brief wants authentic photography of Lebanese SME environments and
explicitly rules out both generic corporate stock and cartoon illustration as
the wrong signal. There is no shoot or budget yet, so each slot renders a flat
palette-only composition that reads as "image pending" rather than pretending to
be the final image.

| Slot | Location | Brief |
|---|---|---|
| Hero | `components/sections/hero.blade.php` | Restaurant counter, owner at the till |
| Founder story | `components/sections/story.blade.php` | Founder portrait, or a clinic front desk |

Both use [`<x-photo-slot>`](../resources/views/components/photo-slot.blade.php).
**To replace:** swap the inner `<div>` for an `<img>`/`<picture>` with the same
wrapper classes. Nothing else on the page depends on the component's internals.

Slot captions live in `lang/*/marketing.php` under `hero.photo_slot` and
`story.photo_slot` — delete them when real photos land.

---

## 4. Legal check on NSSF / Ministry of Finance references — BLOCKER

**Status:** built to the conservative reading. **Still needs the legal check.**

What the site does today:

- ✅ Claims like "built for Lebanese NSSF/VAT rules" and "NSSF payroll" — text only
- ✅ A disclaimer in both the compliance section and the footer:
  *"Independent software. Not affiliated with, or endorsed by, the National
  Social Security Fund or the Ministry of Finance."*
- ❌ **No** NSSF or MoF seals, logos, crests or government imagery anywhere.
  A test (`MarketingHomeTest`) asserts no such asset is referenced.

**Do not add official imagery until the legal check happens.** If counsel
clears specific wording, revisit `compliance.disclaimer` and `footer.disclaimer`
in both locale files.

---

## 5. Pricing footnote needs founder sign-off

`pricing.footnote` currently reads:

> Prices in USD, per month. Local payment methods (Whish, OMT) arrive with
> billing at launch. During early access there is nothing to pay and nothing to
> install.

Deliberately **not** promising a locked-in launch price or a free period to
early-access signups — that is a commercial commitment, not a copy decision.
If the founder wants to make that promise, it belongs here and it will convert
better; it just needs to be a decision, not a default.

The tier figures themselves (Starter $19 / Growth $49 / Pro $99) are from
knowledge base §7 and are treated as settled.

---

## 6. Founder story needs the actual founder

[`components/sections/story.blade.php`](../resources/views/components/sections/story.blade.php)
is structured and voiced for real, but the content is a placeholder standing in
for testimonials that cannot exist pre-launch. Needs:

- The real background (years, which businesses, which cities)
- Whether the signature is a name or stays "Founder"
- Whether the third paragraph's "first group of businesses shapes what ships"
  promise is one you want to make

---

## 7. Source documents — RESOLVED

**Both are now in the repository.** `docs/knowledge-base.md` (8 September 2026)
and `docs/design-brief.md` (9 September 2026).

The payroll and dual-currency work was checked against the knowledge base
directly — see [EOSI-STATUS.md](EOSI-STATUS.md) and
[DUAL-CURRENCY-STATUS.md](DUAL-CURRENCY-STATUS.md) — and one real correction
came out of it: §9 requires an NSSF enrolment date on Employee, which the first
cut of EOSI did not have.

**The marketing site has now been checked against the design brief and matches
it.** It had been built from the spec inlined in the original build request; the
brief confirms that spec rather than contradicting it:

| Brief | Built |
|---|---|
| Five palette hexes + two status colours | Exactly, in `@theme` |
| 60/30/10 usage ratio, no gradients, no asymmetric colour blocking | Held |
| Six-section homepage in order | Hero → pain → features → compliance → pricing → story |
| Real price above the fold, no form | `$19/month` in the hero; verified above the fold at 375px in both languages |
| Inter + a weight-matched Arabic face | Inter + IBM Plex Sans Arabic (the brief's own suggestion), applied by locale |
| Mobile-first | No horizontal overflow at 375px, LTR or RTL |
| Real photography over stock or illustration | Marked placeholder slots — see §3 |
| Component library: buttons, cards, pricing table, nav, forms, status badges | All present in `resources/views/components/`, and the app reuses them |
| Palette must survive an RTL mirror | Logical properties throughout, enforced by a test |

Nothing in the brief required a change to the site. The two things it *did*
surface were documentation gaps, both now closed: the brief references
`README.md` as the project roadmap, which was still stock Laravel boilerplate
(rewritten), and it lists a fifth blocker this file did not carry (§8 below).

One spot-check done against the now-present knowledge base: §11 warns that
leading with "the only one with NSSF" is no longer accurate, because Azkatech
is a real competitor on that wedge. **The homepage does not make that claim** —
it leads with "The combination nobody else sells you", which is §5's corrected
framing. Nothing to fix there.

One claim is worth a second look, though: the WhatsApp feature card is tagged
*"Nobody in this category ships it"*. That is a competitive assertion nobody
has verified against Azkatech or the regional ERPs, and it is the kind of line
a competitor notices. The rest of the site has not been re-read against the
knowledge base.

---

## 8. Accessibility target needs explicit confirmation

Design brief blocker #5, which this file did not previously carry.

The brief defaults to **WCAG AA** for a compliance product and asks for that to
be confirmed rather than assumed. The site and the app were built and *measured*
to AA — the contrast rules in [ARCHITECTURE.md](ARCHITECTURE.md) come from real
canvas measurements, three of which caught failures — so this is a decision to
ratify, not work to schedule.

Worth confirming explicitly because the answer changes things if it is **AAA**:
`warm-gold` and the amber status colour would both fail as text at 7:1, and the
`/70` muted-text floor would have to rise. If AA is the answer, nothing changes.

---

## Not blockers, but worth knowing

- **No analytics.** Nothing is tracked. If the ads in the go-to-market plan
  (§10) need attribution, that is a deliberate follow-up.
- **No CRM or email automation.** Leads land in the `leads` table only, as
  scoped. Someone has to actually look at that table.
- **No sitemap or robots.txt.** Fine pre-launch; add before promoting the URL.
- **Open Graph image.** Meta tags are in place but there is no `og:image`,
  because there is no artwork. WhatsApp and Instagram link previews will be
  text-only until item 1 and item 3 are resolved — worth noting given that
  those are the channels the plan depends on.
