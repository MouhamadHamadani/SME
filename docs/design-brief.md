# Website Design Brief — Decisions Log

*Compiled 2026-09-07. Captures the design direction agreed for the public marketing website. This feeds the "Design" phase on the project roadmap (`README.md`) and is meant to carry forward into the app UI design once that phase starts.*

## Scope decision

**What we're designing first: the public marketing website**, not the app UI yet.

Rationale: pre-launch, the marketing site is what proves the concept, captures early signups, and needs to look credible to the accounting-firm referral partners the go-to-market plan depends on. The app UI (onboarding, invoicing, payroll runs, accountant-seat views, compliance-export flow) is already the next "Design" phase on the roadmap — this brief's visual system (colors, type, components) is meant to extend into that phase, not be redone for it.

## Brand personality

**Trustworthy & institutional** — bank-grade, serious, precise. The buyer is a Lebanese SME owner (restaurant, retail, clinic, salon, distributor) currently on paper/Excel or burned by a $5,000–$15,000 custom build, evaluating software that will touch payroll and government filings. Every competitor in the space (see `knowledge-base.md` §5) gates pricing behind a sales call and looks like generic enterprise ERP software; this product's edge is being self-serve with transparent pricing while still looking *more* trustworthy than the quote-gated incumbents, not less.

Explicitly ruled out for now: "modern & approachable SaaS" (risks reading as not serious enough for a compliance product) and "local & warm" as the primary direction (kept only as a subtle accent, see palette below).

## Brand assets status

Starting from a blank page — no name, logo, or locked-in colors yet. **This is the current blocker**: a final product name is needed before logo/wordmark work can start (the knowledge base still lists this as "working name pending branding"). Everything below is designed to be name-agnostic so it doesn't need rework once a name is chosen.

## Color palette

Avoiding the generic SaaS purple/lavender palette (overused, reads as young/unproven — the wrong signal for a payroll-compliance product) in favor of a fintech/banking-adjacent palette, warmed slightly so it doesn't feel cold or corporate-generic.

| Role | Color | Hex | Notes |
|---|---|---|---|
| Primary | Deep Trust Navy | `#0B3B5C` | Anchor color — headers, primary nav, dominant brand presence. Reads stable and institutional without the coldness of pure black/gray. |
| Secondary | Compliance Green | `#1B7A5B` | Confident, deep (not mint/neon). Doubles as the natural color for "compliant / filed / up to date" states — ties directly to the core pitch. |
| Accent | Warm Gold | `#C9971F` | Used sparingly for CTAs and highlights. Warms up the navy/green pairing and is a quiet nod to Lebanese visual identity (cedar/gold) without going full "local & warm." |
| Background (neutral) | Warm off-white | `#F7F5F1` | Softer than stark white — less clinical. |
| Body text (neutral) | Warm charcoal | `#22262B` | Used instead of pure black. |

Semantic status colors — kept **visually distinct from the brand palette** so a payroll deadline is never lost in decorative color:

| State | Color | Hex |
|---|---|---|
| Filed / current | Green | (brand Compliance Green, `#1B7A5B`) |
| Due soon | Amber | `#D9822B` |
| Overdue | Red | `#C0392B` |

Usage ratio: roughly 60/30/10 — neutral backgrounds dominate, navy carries structure, green and gold appear as intentional accents rather than everywhere.

**RTL constraint:** the palette must survive being mirrored for Arabic. No directional gradients or asymmetric color blocking that would look wrong flipped.

## Template / layout direction

Building semi-custom on the existing Tailwind stack rather than an off-the-shelf template or UI kit — differentiation depends on not looking like every other vendor's site.

**Homepage flow:**

1. Hero — clear bilingual value statement, real price visible above the fold (the single biggest differentiator vs. every "contact us for pricing" competitor — lead with it)
2. Pain-point section — speaks directly to the Excel/paper/expensive-custom-build reality
3. Feature section — organized around the actual wedge (NSSF + EOSI + VAT + dual-currency + WhatsApp delivery), not generic "invoicing" bullets
4. Compliance/trust section — visually signals "always current with NSSF/VAT changes"; worth a dedicated, well-designed module since it's the core promise
5. Transparent pricing table — Starter / Growth / Pro (per `knowledge-base.md` §7)
6. Founder-story / "why we built this" section — substitutes for testimonials that don't exist pre-launch

**Typography:** a genuine Latin/Arabic pairing, not a translated afterthought. Latin: a clean geometric/humanist sans (e.g., Inter). Arabic: a typeface matched for weight and x-height (e.g., IBM Plex Sans Arabic or Noto Sans Arabic). Full RTL mirroring — nav, directional icons, and CTA placement all flip, not just text.

**Layout:** mobile-first — much of the actual audience will land from a WhatsApp or Instagram ad on a phone, per the go-to-market plan.

**Imagery:** real photography of Lebanese SME environments (a restaurant counter, a clinic front desk) over generic stock-corporate photography or cartoon illustration — a credibility signal that the product understands this specific market.

## Requirements — blockers before design execution starts

1. **Product name.** Blocks logo, wordmark, and domain work. Currently unresolved ("working name pending branding" in `README.md`).
2. **Native Arabic copywriter/reviewer.** Arabic copy needs to be written or reviewed directly by someone fluent in Lebanese business Arabic, not translated from English after the fact — translated marketing copy undercuts the "built for us" positioning.
3. **Photography vs. illustration vs. icon-only decision.** Budget/time tradeoff — real photography needs a shoot or a Middle-East-relevant stock budget, which is thin in this category.
4. **Legal check on NSSF/MoF references.** Can likely claim "NSSF-compliant" but probably can't use official seals/logos without permission — needs a quick check.
5. **Accessibility target confirmation.** Defaulting to WCAG AA for a compliance product; needs explicit confirmation.

## Outcome — what this design phase produces

1. Brand starter kit: color system (above), Latin/Arabic type pairing, iconography direction. (No full logo yet — needs the name first.)
2. Fully designed homepage in both English (LTR) and Arabic (RTL), at desktop and mobile.
3. A component library — buttons, cards, pricing table, nav, forms, status badges — so the rest of the site, and later the app (per the roadmap's "Design" phase), gets built consistently instead of page-by-page from scratch.

## Next step

Mock up an actual homepage concept against this palette so decisions are being made against something visual rather than hex codes and descriptions — pending go-ahead.