# SME Compliance Accounting & Payroll Suite — Knowledge Base

*Working name pending branding. Compiled 2026-09-07. This is the founding reference document for the build — it feeds the next phases (system structure, database schema, UI/UX design, and implementation) and should be updated as decisions are made and as Lebanese regulations change.*

**Origin:** This is opportunity #1 from the `lebanon-software-opportunities-report.md` project doc (the highest-conviction pick in that report: 6.8/10 average score, ranked #1 in the "Build This First" analysis). This document takes that opportunity and goes one layer deeper — specifically on the regulatory mechanics a compliance product cannot get wrong — using targeted research beyond what the original market-scan covered.

---

## 1. Product Vision

A bilingual (Arabic/English) cloud accounting and payroll SaaS built specifically for Lebanese SMEs, where Lebanon-specific compliance — NSSF contributions, end-of-service indemnity (EOSI), VAT, and LBP/USD dual-currency accounting — is a native, always-current feature, not a bolt-on. Sold self-serve at transparent, published prices, undercutting both the "contact us" pricing of local ERP incumbents and the $5,000–$15,000 custom-build alternative.

**The wedge, restated after deeper research (see §3 and §5):** it is not "nobody does NSSF compliance" — one competitor (Azkatech's Odoo localization) already does NSSF, EOSI, and MoF filing automation reasonably well. The real wedge is the *combination* nobody currently offers together: self-serve signup, transparently published pricing, dual-currency accounting, and NSSF/EOSI/VAT compliance, all in one product, without a sales call or an implementation partner. That combination is still open.

## 2. Market Context (summary — full detail in the source report)

- Target customer: owner-operated SMEs, 5–100 employees — restaurants, retail, clinics, salons, small distributors — currently on Excel/paper or an expensive custom build.
- Regulatory tailwind: Lebanon's Ministry of Finance began mandating electronic submission of tax documents from October 2025 (see §3.4 for what this actually requires — it is narrower than a formal e-invoicing mandate like Saudi's ZATCA).
- Willingness to pay is evidenced: Lebanese SMEs already pay $5,000–$15,000 upfront for custom compliance software, or $25–40/user/month for regional ERP platforms.
- Macro backdrop: Lebanon's economy runs on cash and "fresh" physical USD outside the banking system; card rails and automated recurring billing are unreliable (see the project's `tymelo-saas-feasibility-report.md` for a detailed treatment of this — it recommends flexible local payment collection — Whish Money, OMT, bank transfer — and prepay terms over assuming smooth recurring card billing, and the same logic applies here for how *this* product should collect its own subscription fees).
- Currency and rates move fast and are revalued administratively, not just by inflation — see §3 for concrete examples from the last 12 months. Any hardcoded rate, ceiling, or bracket in this product will be wrong within months.

## 3. Regulatory & Compliance Deep Dive

This section is the primary value-add of this document over the original market report. Every figure below is sourced and dated; several genuinely conflict across sources (normal for Lebanon, where NSSF issues memos that get revised or superseded within weeks — see 3.1). Nothing here should be treated as legally final — §12 lists what still needs sign-off from a Lebanese accountant/labor lawyer before it ships in the product, exactly as the original report recommended.

### 3.1 NSSF (National Social Security Fund) contributions

NSSF contributions have three branches, each with its own rate and its own salary ceiling. As of the most recent evidence found (September 2026):

| Branch | Employer | Employee | Salary ceiling (monthly) |
|---|---|---|---|
| Sickness & Maternity (medical) | 8% | 3% | LBP 120,000,000 (per NSSF Memo #805, 22 Aug 2025) |
| Family & Educational Benefits | 6% | 0% | LBP 28,000,000 (per Decree No. 2923, effective 1 May 2026 — up from LBP 18,000,000) |
| End-of-Service Indemnity | 8.5% | 0% | No ceiling |
| **Total** | **22.5%** | **3%** | — |

Family allowance benefit amounts (paid out to employees with dependents), effective 1 May 2026: spouse LBP 2,100,000/month; per child LBP 1,155,000/month (max 5 children); combined family-benefit ceiling LBP 7,875,000/month.

**Why this table matters more than the numbers in it:** the sickness/maternity ceiling was set to LBP 140,000,000 by NSSF Memo #801 (1 Aug 2025), then *cancelled and reset* to LBP 120,000,000 by Memo #805 just three weeks later (22 Aug 2025). The family-allowance ceiling separately jumped from LBP 18,000,000 to LBP 28,000,000 nine months after that. This is the normal cadence of Lebanese payroll compliance right now — rate and ceiling changes arrive by NSSF memo or decree with little notice, sometimes reversing a change made weeks earlier. **The product's payroll engine must treat every rate, ceiling, and benefit amount as an effective-dated row in a rules table, never a constant in code**, with an admin-editable "compliance rules" panel so a rate change doesn't require a deploy. This is a first-order architectural requirement, not a nice-to-have — flag it explicitly for the schema/structure phase.

### 3.2 End-of-Service Indemnity (EOSI) — the payout formula

Distinct from the 8.5% employer *contribution* above (which funds the EOSI branch on an ongoing basis), EOSI is what NSSF pays an employee on separation:

- **Base:** average taxable salary over the last 12 months (excludes overtime and bonuses, *unless* they were paid consistently for the preceding 3+ years, in which case they're included).
- **Formula:** base salary × years of service × a seniority coefficient:
  - Under 5 years of NSSF enrollment: 50% of the calculated amount
  - 5–10 years: 65%
  - 10–20 years: 85%
  - 20+ years: 100%
- NSSF (not the employer) formally calculates and administers the payout; the employer's role is accurate contribution history and salary reporting.

**Watch item — Law No. 319:** enacted 22 December 2023, Law 319 authorizes a shift from this lump-sum EOSI model to a monthly pension system, with employees under 49 expected to transition to the new model once implementing decrees are issued. As of the most recent sources found (2026), implementation is still incomplete — NSSF has been digitizing its records as a documented "step toward" the new system, but full application decrees were not yet confirmed as issued. **This could materially change EOSI mechanics during this product's life.** Build the EOSI module as a swappable calculation strategy (lump-sum formula today, pension-accrual formula later) rather than one hardcoded formula, and put a monitoring note in the team's calendar to re-check NSSF/MoF announcements quarterly.

### 3.3 VAT

- Standard rate: **11%**, current and in force.
- A hike to **12%** was approved by Cabinet in February 2026 but, per the most recent evidence found, was still pending final Parliamentary ratification (the increase is bundled with a fuel-tax rise and new shipping-container fees, aimed at funding public-sector salary relief). Treat 12% as "likely but not yet law" — build the VAT rate as a dated, configurable parameter (same pattern as §3.1), not a constant, so the product doesn't need a hotfix the day it passes.
- Zero-rated: exports, international transport. Exempt: banking and insurance operations.

### 3.4 The October 2025 e-filing mandate — what it actually requires

The Ministry of Finance mandate (effective 6 October 2025) requires **electronic submission** of: income tax returns, salary tax (payroll withholding / "R10") filings, inheritance tax documents, VAT submissions, and real-estate tax filings. Two things worth being precise about, since they directly shape MVP scope:

1. **Submission method is email-based, not a structured API/XML e-invoicing standard.** This is meaningfully lighter-weight than Saudi Arabia's ZATCA or the UAE's forthcoming e-invoicing framework (both mentioned in the original report as future expansion targets, and both are true structured e-invoicing mandates). For Lebanon today, the product's job is to generate correctly formatted, filing-ready documents (PDF/Excel) that a business owner or their accountant can submit — not to integrate with a live government API. That's a meaningfully smaller build than "e-invoicing compliance" implies.
2. **Design for the mandate to tighten.** The regional direction (Saudi ZATCA live, UAE mandating from 2026, Tunisia and Jordan expanding e-invoicing scope) makes it likely Lebanon eventually moves toward a structured e-invoicing standard too. Model invoice data with the fields a future structured e-invoice would need even if unused today: sequential/non-reusable invoice numbering per legal entity, a tax registration number field, itemized VAT breakdown per line, and a hash or reference chain linking invoices in sequence. Retrofitting this into a live product with real customer data is much more painful than including it from the schema phase onward.

### 3.5 Payroll withholding tax (income tax on salaries)

Lebanon applies a progressive personal income tax on salaries. Sources found disagree on the exact bracket structure — one source cites a 2%–25% range, another gives a specific LBP-bracket table from 4% up to 25% (see table below) — which is consistent with these figures not having been consistently revalued for the post-2019 currency collapse across every publisher. **Do not hardcode either version without an accountant confirming the current official Ministry of Finance schedule** (see §12).

| Monthly salary band (approx., as one source states it) | Marginal rate |
|---|---|
| Up to LBP 9,000,000 | 4% |
| LBP 9M–24M | 7% |
| LBP 24M–54M | 12% |
| LBP 54M–104M | 16% |
| LBP 104M–225M | 21% |
| Over LBP 225M | 25% |

Employers file quarterly R10 withholding declarations, due 15 April, 15 July, 15 October, and 15 January.

### 3.6 Other payroll/labor facts worth having in the data model

- Minimum wage: LBP 28,000,000/month (~USD 312 at ~LBP 90,000/USD), effective 1 August 2025; daily minimum LBP 1,300,000.
- Standard hours: 8/day, 48/week; overtime premium 50% beyond 48 hours/week.
- NSSF registration and MoF registration are **separate processes** with separate identifiers and separate filing tracks — the product needs both IDs per tenant, not one.
- Foreign-currency salaries must be reported to MoF using MoF-prescribed exchange rates, not the market/parallel rate — the dual-currency engine needs a distinction between "market rate" (for the business's own books) and "MoF-prescribed rate" (for filings), which can diverge.
- USD/EUR-denominated withholding tax must be filed in the same currency as it was withheld (a rule in force since April 2024) — reinforces that the ledger must track currency of transaction, not just a converted LBP equivalent.
- Corporate tax rate: 17% (context for a likely future module, out of scope for MVP payroll/VAT focus).

### 3.7 Confidence levels, plainly stated

- **High confidence (multiple consistent sources):** NSSF branch structure and rates (8% / 6% / 8.5% employer, 3% employee), current 11% VAT rate, October 2025 e-filing mandate's existence and scope, minimum wage, EOSI seniority-coefficient formula.
- **Medium confidence (single or partially conflicting sources):** exact current NSSF ceilings at any given moment (they move faster than published guides can track — always re-verify against the latest NSSF memo before launch), income tax withholding bracket table, VAT-to-12% timing.
- **Explicitly unresolved, needs a professional:** whether Law 319's pension transition has any effective date yet, the precise penalty regime for late/incorrect e-filings, and whether any SME size threshold exempts small businesses from the October 2025 mandate.

## 4. Target Customers & Personas

Reused from the source report, unchanged — this is where that report's evidence was strongest:

- **Restaurant/retail owner**, 5–30 staff, currently doing books in Excel or on paper, pays an outside accountant a flat monthly cash fee to "handle NSSF and VAT," wants to stop making payroll errors and stop paying for a custom system.
- **Clinic/salon operator**, small team, similar profile, plus specific interest in EOSI accuracy since staff turnover in these sectors is higher.
- **Small distributor**, 20–100 staff, more complex payroll (mixed contract types, sales commissions), most likely to need the Pro tier and multi-branch reporting.
- **The client's outside accountant** — not the buyer, but a critical secondary user. The accountant-seat feature and the 20%-referral-commission channel both depend on this person seeing the product as making their job easier, not threatening it.

## 5. Competitive Landscape

Deeper than the original report's list — pricing and feature claims pulled directly from each vendor's own site/comparison pages where available (September 2026).

| Vendor | Pricing | Multi-currency | VAT compliance | NSSF/payroll compliance | Self-serve? | Notable |
|---|---|---|---|---|---|---|
| **Visual Dolphin** (Software Design Consulting) | Custom quote | Yes, parallel rates | Localized VAT filing docs | Not advertised | No | Cloud/hybrid |
| **Wizard Cloud ERP** (Wizard Solutions) | Custom quote, free demo | Yes | VAT-regulation reporting module | Not advertised | No | Cloud-native |
| **Azkatech (Odoo Lebanon localization)** | ~$25–40/user/mo cloud + implementation fee; free basic tier | Yes, dual-currency payslips | MoF tax-declaration automation | **Yes — NSSF, family allowances, employee deductions, auto monthly NSSF declarations, EOSI by contract/tenure** | Partial (demo-gated, needs partner implementation) | **Closest existing competitor on the exact NSSF/EOSI/MoF wedge.** Reference clients include Hallab, Sleep Comfort. |
| **Potech ERP/MicroStep** | Custom enterprise quote | Yes, parallel rates | Claims continuous updates for MoF/VAT changes | Not advertised | No | Cloud or on-prem |
| **Socrate Platinum (AIMS)** | Quote-based | Yes, dynamic revaluation | Dual chart of accounts, statutory reports | Not advertised | No | — |
| **Omega Software SAL** | Custom quote (hardware/terminal-based) | Yes, multi-currency retail | Automated VAT calc, MoF-aligned reporting | Not advertised | No | POS-centric |
| **NORIA Business Suite (EDM)** | Custom corporate quote | Yes | Not detailed | Not advertised | No | POS/fleet/inventory focus |
| **ACTS Accounting** | Tailored quote | Yes | Corporate income tax + VAT docs | Not advertised | No | — |
| **Brain Accounting** | Custom quote | Yes, ledger separation | Trial balance/GL for audit | Not advertised | No | Local network + offsite backup |
| **MAPOS** | Not disclosed; 500+ businesses claimed (per source report) | Native USD/LBP dual ledgers | Not disclosed in available content | Not disclosed | Unclear | **Offline-first architecture (works through power cuts), bilingual AR/EN, integrated POS+inventory+accounting, built-in AI bookkeeping/anomaly-detection agents.** Emerging AI-forward competitor to watch closely. |

**What changed from the original report's read:** the original report treated NSSF/EOSI compliance as essentially uncontested. It isn't — Azkatech's Odoo localization already automates NSSF, EOSI, and MoF filings. What *is* still uncontested is the full combination: self-serve signup with published pricing (every vendor above, including Azkatech, gates pricing/onboarding behind a demo or sales conversation) **and** NSSF/EOSI **and** VAT **and** genuine dual-currency accounting **and** a price point under $99/month. MAPOS's offline-first design and AI bookkeeping features are also worth tracking as a general product-quality bar, even without confirmed payroll-compliance claims from them.

## 6. Feature Scope

### MVP (Phase 1 — matches the original report's 10–12 week estimate)
- Multi-tenant invoicing with MoF-compliant, filing-ready templates
- VAT computation at the current configurable rate (§3.3), exportable filing package
- Basic payroll: NSSF contribution calculation across all three branches (§3.1), with rate table as effective-dated, admin-editable data — not hardcoded
- EOSI accrual tracking and estimate display, calculated via a swappable formula strategy (§3.2)
- Bilingual (Arabic/English) UI
- True LBP/USD dual-currency ledger, tracking both "market rate" and "MoF-prescribed rate" per §3.6, with daily rate entry (manual or API-fed) and revaluation reporting
- Accountant seat: read/export access for the client's outside accountant, no re-entry required
- PDF invoice/payslip generation, delivered via WhatsApp Business Cloud API
- CSV/PDF export formatted for the client's outside accountant

### Phase 2 (post-MVP)
- Inventory management
- Multi-branch consolidation and reporting
- Whish Pay / OMT integration for the product's *own* subscription billing (see the Tymelo report's payment-rail findings — recurring card billing is unreliable in Lebanon; the product should offer flexible prepay terms, not assume smooth monthly card charges)
- Income tax (payroll withholding / R10) module, once bracket figures are accountant-confirmed
- Structured e-invoice-ready data fields activated, if/when MoF formalizes a schema (§3.4)

### Explicitly out of scope for now
- Corporate income tax filing (17% rate) — a plausible future module, not core
- Direct government API integration — not required by the current mandate (§3.4)
- A formal pension-accrual EOSI model — build the hook, not the full implementation, until Law 319's decrees are confirmed issued

## 7. Business Model & Pricing

Unchanged from the source report, still the right starting point:

- **Starter** — $19/mo: up to 5 users, invoicing + VAT
- **Growth** — $49/mo: up to 20 employees, adds payroll/NSSF
- **Pro** — $99/mo: multi-branch, multi-currency reporting, accountant seats
- Accounting/audit firms recruited as referral partners, 20% first-year commission per referred client
- Given Lebanon's cash/USD-outside-banking reality (see the Tymelo report in this project), plan the product's own billing around flexible collection (Whish, OMT, bank transfer, possibly quarterly/semi-annual prepay) rather than assuming smooth recurring card billing — this is a lesson directly transferable from that adjacent analysis.

## 8. Technical Architecture (starting point — detailed in the "structure" phase)

- **Platform: a website (browser-based web app), not a native mobile or desktop app.** Laravel + Livewire is a server-rendered web framework — that choice already settles the "what are we building" question. The business owner and their accountant log in from a browser (desktop or mobile) to invoice, run payroll, and pull reports; end customers and employees never need to install anything — they just receive PDFs (invoices, payslips) via WhatsApp. Tailwind makes the UI responsive, so it works fine on a phone browser without a separate native build. A PWA "add to home screen" wrapper is a cheap later enhancement if an app-like feel is wanted, but a true native iOS/Android or desktop app is out of scope — it would roughly double build time and add app-store review overhead for no benefit this specific product needs.
- **Stack:** Laravel + Livewire + Tailwind (matches the founder's existing stack), MySQL, hosted on AWS
- **Compliance rules engine:** a dedicated, effective-dated rules table (NSSF rates/ceilings, VAT rate, income-tax brackets, minimum wage) editable via an internal admin panel, versioned so historical payroll runs remain reproducible even after a rate changes — this is the single most important architectural decision coming out of §3
- **Currency:** daily exchange-rate snapshot job (scheduled, with manual fallback for outages), storing both a "market rate" and a separate "MoF-prescribed rate" per day
- **Documents:** PDF generation for invoices, payslips, and MoF filing packages
- **Delivery:** WhatsApp Business Cloud API for invoice/payslip delivery
- **Multi-tenancy:** given this is a compliance product handling salary and financial data, tenant isolation deserves real design attention up front — see the project's `tymelo-saas-feasibility-report.md` for documented Laravel multi-tenancy pitfalls (silent tenant-scope bugs from `orWhere()` precedence errors, queue workers losing tenant context on background jobs) that apply directly here, arguably with higher stakes given the data involved

## 9. Data Model Starting Points (for the schema phase)

Not a full schema yet — this is the entity list to design against next:

- Tenant / Business (with separate NSSF registration ID and MoF registration ID, per §3.6)
- User (with role: owner, staff, accountant-seat)
- Employee (contract type, hire date, NSSF enrollment date — needed for the EOSI seniority coefficient)
- Compliance Rule Version (branch, rate, ceiling, benefit amount, effective-from date — the table underpinning §3.1–§3.3)
- Payroll Run (period, status, linked to the Compliance Rule Version in force at that time)
- Payslip (per employee per run, both LBP and USD figures where relevant)
- NSSF Contribution Record (per branch, per employee, per period)
- EOSI Accrual Ledger (running estimate per employee, formula-versioned)
- Invoice / Invoice Line (VAT breakdown per line, sequential numbering, currency of transaction)
- Exchange Rate Snapshot (date, market rate, MoF-prescribed rate)
- Chart of Accounts / Ledger Entry
- Compliance Export Batch (what was generated, when, for which filing deadline)

## 10. Go-to-Market (summary — full detail in the source report)

- Two to three accounting/audit firms as referral partners (20% first-year commission)
- Free Excel-to-SaaS migration offer via Instagram/Facebook ads and WhatsApp Business catalogs
- Presence at Lebanese SME/retail trade events (e.g., Horeca Beirut)
- First 10 customers realistically from the founder's personal/professional network plus the first two accounting-firm partners, within 4–6 weeks of MVP launch
- First $1,000 MRR: ~25–35 customers, 3–5 months post-launch
- First $10,000 MRR: ~200–250 customers (or fewer, Pro-tier-weighted), 12–18 months, likely needing a sales/success hire

## 11. Risks (compliance-specific, additive to the source report's risk list)

- **Rate-change velocity is the core operational risk**, not a one-time build risk (see §3.1's Memo #801→#805 example — a reversal within three weeks). Ongoing maintenance of the compliance rules table is a permanent cost center, not a launch-and-forget feature.
- **Bracket/formula disagreement across sources (§3.5, §3.7)** means the product cannot go live on withholding-tax logic without a named accountant sign-off — this is now a concrete, scoped pre-launch task, not a vague caveat.
- **Law 319 could change EOSI mechanics mid-product-life.** The swappable-formula architecture in §3.2/§8 is the mitigation; the residual risk is a support/comms burden when it activates, not a rebuild.
- **Azkatech is a real, not hypothetical, direct competitor on the payroll-compliance wedge specifically** — competitive messaging should lead with self-serve + transparent pricing + dual-currency completeness, not "the only one with NSSF," which is no longer accurate.

## 12. Open Questions — Needs a Lebanese Accountant/Labor Lawyer Before Launch

1. Confirm the *current* NSSF ceilings and rates against the latest NSSF memo at build time — the numbers in §3.1 are correct as of the sources found in September 2026 research but are exactly the kind of figure that changes on short notice.
2. Confirm the authoritative income-tax withholding bracket table (§3.5) — two sources disagreed.
3. Confirm whether Law 319 has any implementing decree with an actual effective date yet, and if so, what it requires of employers/software.
4. Confirm whether any SME size or revenue threshold exempts smaller businesses from the October 2025 e-filing mandate.
5. Confirm penalty/enforcement exposure for late or incorrect e-filings — not found in research to date.
6. Validate the EOSI seniority-coefficient formula (§3.2) and the "consistent for 3+ years" bonus/overtime inclusion rule against current NSSF guidance, ideally with a worked example.
7. Confirm whether the 12%→11% VAT question has resolved by the time of build (§3.3) and get the product's config default right at ship time.

## 13. Glossary

- **NSSF** — National Social Security Fund, Lebanon's social-security administrator; collects contributions and pays out benefits (medical, family allowance, end-of-service).
- **EOSI** — End-of-Service Indemnity, Lebanon's lump-sum severance-style benefit (see §3.2), currently NSSF-administered and under a legislated (but not yet fully implemented) transition to a pension model.
- **MoF** — Ministry of Finance, Lebanon's tax authority.
- **R10** — the quarterly payroll withholding-tax declaration filed with MoF.
- **"Fresh dollars"** — physical USD held/transacted outside Lebanon's frozen banking-sector deposits, the de facto currency of much of the real economy since 2019.
- **Whish / OMT** — Lebanese digital payment/money-transfer rails used in place of unreliable card/bank-transfer rails.

## 14. Roadmap — Next Steps

This document is Phase 0. The next phases, in the order the user asked for:

1. **Structure** — define the application architecture: modules, multi-tenancy approach, service boundaries (payroll engine, compliance rules engine, invoicing, reporting), integration points (WhatsApp API, exchange-rate feed, PDF generation).
2. **Schema** — turn §9's entity list into an actual database schema, with the compliance-rules-as-effective-dated-data pattern (§3.1, §8) as a first-class design constraint, not an afterthought.
3. **Design** — UI/UX for the bilingual (Arabic/English, RTL-aware) product: onboarding, invoicing flow, payroll-run flow, accountant-seat view, compliance-export flow.
4. **Build** — implementation on the Laravel/Livewire/Tailwind stack, MVP scope per §6, targeting the 10–12 week solo estimate (6–8 weeks with a second developer) from the source report.

## Sources

Compliance research (September 2026):
- [Lebanon - Individual - Other taxes — PwC Worldwide Tax Summaries](https://taxsummaries.pwc.com/lebanon/individual/other-taxes)
- [Lebanon Payroll Compliance Guide (2026) — Promenics](https://www.promenics.com/blog/lebanon-payroll-compliance-guide-2026/)
- [Lebanon Payroll & Employer of Record Guide — Neeyamo](https://www.neeyamo.com/global-guide/lebanon)
- [Lebanese Labor Law and NSSF — Mira-Cle](https://mira-cle.com/lebanese-labor-law-and-nssf/)
- [CRS News Flash 11 May 2026 — Lebanon NSSF Family Allowances and Contribution Ceiling](https://www.crs.co.za/crs-news-flash-11-may-2026-lebanon-nssf-family-allowances-and-contribution-ceiling/)
- [NSSF Memo #801 (1 Aug 2025) — Aldic](https://www.aldic.net/nssf-memo-801-dated-1st-august-2025-increasing-the-nssf-ceiling-to-lbp-140-million/)
- [NSSF Memo #805 (22 Aug 2025), cancelling Memo #801 — Aldic](https://www.aldic.net/nssf-memo-805-dated-22-aug-2025-cancels-memo-801-and-sets-the-nssf-ceiling-at-lbp-120-million/)
- [NSSF Law #319 — Aldic](https://www.aldic.net/nssf-law-319/)
- [Lebanon VAT Guide 2026 — Kintsugi](https://trykintsugi.com/sales-tax-guides/middle-east/lebanon)
- [Lebanon Plans VAT Hike from 11% to 12%, Pending Parliamentary Approval — VATupdate](https://www.vatupdate.com/2026/02/26/lebanon-plans-vat-hike-from-11-to-12-pending-parliamentary-approval/)
- [12% VAT & New Fuel Taxes: What the Lebanese Gov't Just Approved — Beirut.com](https://www.beirut.com/en/770834/12-vat-new-fuel-taxes-what-the-lebanese-govt-just-approved/)
- [Lebanon MoF Mandates Electronic Submission of All Tax Documents from October 2025 — VATupdate](https://www.vatupdate.com/2025/11/19/lebanon-mof-mandates-electronic-submission-of-all-tax-documents-from-october-2025/)

Competitive research (September 2026):
- [Compare The 10 Best Accounting Software in Lebanon — Audit Firms Lebanon](https://auditfirmslebanon.com/accounting-software/)
- [Best Accounting & Inventory Software in Lebanon and MENA 2026 — MAPOS](https://www.mapos.tech/en/blog/best-accounting-software-lebanon-mena-2026)
- [Lebanese Payroll System — Azkatech](https://azka.tech/lebanese-payroll-system/)

Plus the full source list in `lebanon-software-opportunities-report.md` (this project), which this document extends rather than replaces.