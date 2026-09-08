# WhatsApp delivery

> **Nothing is sent until you configure it, and the default driver sends
> nothing at all.** Read §1 before switching it on.

The marketing site promises invoices delivered over WhatsApp. This is that,
built against Meta's WhatsApp Cloud API.

---

## 1. What is still needed from you

The code is complete. What it cannot do is get you a WhatsApp Business account
— that is an application to Meta, not a coding task.

| | Needed for | Where it goes |
|---|---|---|
| A **number registered on the Cloud API** | sending at all | Settings → WhatsApp |
| A **permanent access token** | sending at all | Settings → WhatsApp (stored encrypted) |
| An **approved message template** | sending at all | `WHATSAPP_INVOICE_TEMPLATE` |
| App secret + verify token | delivery receipts | `.env` |
| A **payment method on the WhatsApp account** | sending past the free allowance | Meta Business Manager |

> **Confirm all of this against current Meta docs before going live.** The
> endpoint (`/{phone-number-id}/messages`), the template payload, and the
> webhook envelope in this code follow the Cloud API as of the pinned
> `WHATSAPP_API_VERSION`. Meta changes these between versions — and changes
> the setup steps, limits and pricing more often than that. A wrong shape
> fails at send time, not at deploy time.

### Having a WhatsApp Business number is not the same as being ready

The number you already use is almost certainly signed in to the **WhatsApp
Business app** on a phone. That number **cannot be on the app and the Cloud
API at the same time.** Moving it across ends its use in the app: no more
replying from the handset, and the chat history stays on the device rather
than coming with it.

So decide first:

- **Move the existing number.** Customers already know it. You lose the app.
- **Register a different number.** Keep the app for conversations, send
  invoices from a second number. It needs to receive one SMS or voice call to
  register, and a landline works.

There is no third option where one number does both.

### The one-time setup, in order

Steps 1–5 are done once by you as the operator; step 6 is per business.

1. **Meta Business portfolio**, then **business verification** — commercial
   registration and proof of address. Until it is verified you are capped at a
   low daily number of business-initiated conversations and cannot get a
   display name approved. Verification is the slow step; start it first.
2. **A Meta app** of type Business with the WhatsApp product added. This is
   where the **app secret** comes from (`WHATSAPP_APP_SECRET`).
3. **Register the number** under WhatsApp → API Setup and choose the display
   name customers will see. The name goes through its own review, separately
   from business verification.
4. **A system user with a permanent access token**, with the
   `whatsapp_business_messaging` and `whatsapp_business_management`
   permissions. The token shown on the API Setup screen expires in 24 hours —
   it is for testing, and using it in production means sends stop the next day.
5. **A payment method** on the WhatsApp Business account. Meta charges for
   business-initiated messages beyond a free allowance; with no card on file
   sending stops once it runs out. An invoice notification bills at the
   **utility** rate, the cheapest category.
6. **The template**, and then the business connects itself — see §3.

### Then switch it on

```
WHATSAPP_DRIVER=cloud
WHATSAPP_APP_SECRET=...          # from the Meta app
WHATSAPP_VERIFY_TOKEN=...        # any long random string you also paste at Meta
WHATSAPP_INVOICE_TEMPLATE=invoice_notification
WHATSAPP_INVOICE_TEMPLATE_LANGUAGE=ar
```

Leave `WHATSAPP_DRIVER` alone until the template is approved. Everything else
can be configured while the driver is still `log`.

### The template constraint

An invoice notification is **business-initiated**, so Meta requires a
**pre-approved template**. Free-form text is only allowed inside the 24-hour
window after a customer messages you, which never applies here.

That is why `TemplateMessage` models only templates — free text would be
rejected by the API, and discovering that in production is worse than not
offering it. The template's variable **count and order** are part of what Meta
approved; `SendInvoice::variables()` currently sends five, in this order:

1. customer name
2. business name
3. invoice reference
4. total with currency
5. due date

Change the template, change that method — a mismatch is rejected at send time.

---

## 2. Drivers

```
WHATSAPP_DRIVER=log     # default: writes what it would send, sends nothing
WHATSAPP_DRIVER=cloud   # real
```

**`log` is the default deliberately.** Every fresh install and every developer
machine is unconfigured, and the failure mode of a live driver by default is
messaging a real customer during a test. The log driver returns a
`wamid.LOG-…` id shaped like Meta's, so nothing downstream learns to expect a
different format from the fake than from the real thing.

The invoice screen and the settings screen both say **"test mode … nothing is
sent"** whenever the driver is not `cloud`. Believing an invoice was
delivered when nothing left the machine is the worst failure this feature has,
so it is stated on screen rather than left to the reader's memory.

---

## 3. Credentials are per business, and businesses connect themselves

**Settings → WhatsApp**, in the app. Owner only — the `manage-settings` gate
is deliberately narrower than `manage-payroll`, because the token can message
customers *as* the business and changing it changes who those customers hear
from. That is not a visiting accountant's decision.

The screen takes the **phone number id** and the **access token**, and while
the driver is `cloud` it **checks them with Meta before storing anything** —
a read-only `GET` on the number itself. A wrong id, an expired token or a
token belonging to a different business all fail on that screen, in front of
the person who can fix it. Without the check, the first sign of a bad paste is
a customer's invoice failing.

Meta's own error text is shown verbatim. Ours would be a guess about which of
seven setup steps is missing.

The token goes in **write-only**: it is never rendered back, not even masked,
and `bootstrap/app.php` adds it to `dontFlash` so a failed validation cannot
leave a bearer credential sitting in the session. **Check the connection**
re-verifies a stored token, because tokens get revoked without anyone telling
us and the alternative to a button is hearing about it from a customer.

The same operation still exists on the command line for support work:

```bash
php artisan whatsapp:connect arzclinic --phone-number-id=1234567890
# prompts for the token, hidden

php artisan whatsapp:connect arzclinic --show
```

A platform-wide token would let one business's invoices go out from another's
number. The access token is **encrypted at rest** (`Crypt::encryptString`) in
the tenant's settings. The command prompts for it rather than taking an
argument, so it does not land in shell history or a process list.

A token encrypted under a rotated `APP_KEY` is treated as **not connected**
rather than sent as a garbage bearer token.

### Who owns the Meta account: three models

This is a product decision, not a technical one, and it is worth making
deliberately.

**A. Each business brings its own Meta app and token** — what the screen above
supports. Correct isolation, no shared reputation, no work for you. It also
asks a Beirut retailer to complete business verification and create a system
user in Meta Business Manager. Expect most of them not to finish.

**B. You onboard them as a Tech Provider (Embedded Signup).** One Meta app,
yours. The business clicks a button, signs in to Facebook in a popup, and you
receive a business-scoped token and register their number for them — the
version of this that a normal customer can actually complete. It needs Tech
Provider status, App Review for the two WhatsApp permissions, and your own
business verification.

> **Not built.** The token exchange and number registration would be written
> against an API this codebase cannot call until that approval exists, and
> untested guesses about a payload shape are exactly what the rest of this
> system refuses to do. Ask for it once the Meta app is approved.

**C. One number — yours — sends for everyone.** Simplest, works today, one
template. But every customer sees *your* business name rather than their
supplier's, which undercuts the reason invoices are sent on WhatsApp at all;
and one business's blocks and spam reports degrade the quality rating for all
of them. Also not built — `SenderFactory` refuses when a tenant has not
connected, rather than quietly falling back to a platform number.

---

## 4. Delivery receipts

Meta calls **one webhook url for the whole app**, not one per business, so the
endpoint is central and unauthenticated:

```
GET  /webhooks/whatsapp   verification handshake
POST /webhooks/whatsapp   status callbacks
```

It identifies the business only by `phone_number_id`, which is why that is a
real indexed column on `tenants` rather than a key inside the settings JSON —
the lookup happens *before* tenant context can be entered at all.

**Every callback is HMAC-verified** (`X-Hub-Signature-256`, SHA-256 of the
**raw** body keyed with the app secret) before anything is read. Without it,
anyone could post fake "delivered" statuses and a business would believe an
invoice arrived when it never did. With no app secret configured, every
callback is rejected — never accepted unverified.

Statuses move `sent → delivered → read`, or `failed` with Meta's reason. A
`read` callback backfills `delivered_at`, because Meta does not guarantee the
delivered callback arrives.

Anything unrecognised — an unknown number, an uninterpretable payload — is
**acknowledged with 200**. Meta retries anything else, and retrying will not
make an unknown number known.

---

## 5. Sending

**Invoices → open an issued invoice → Send on WhatsApp.** Gated on
`manage-payroll` (owner and accountant), like everything else that acts.

The delivery row is written **before** the API call and updated after, so a
send that crashes mid-flight leaves a visible `queued` row rather than no
trace. "Did the customer get it?" has to be answerable months later, which is
what `whatsapp_messages` is for.

Sending is **synchronous**: the person clicking wants to know whether it went,
and a queued job that fails silently is worse than a slow button. The row
already models a `queued` state, so moving it to a job later is a small change.

### Phone numbers

Lebanese numbers are written half a dozen ways and Meta accepts exactly one:
E.164 digits, no plus. `App\Support\PhoneNumber` normalises them.

`+` or `00` is treated as the caller **declaring** the number international —
that prefix is the only reliable way to tell `+33 6…` (France) from a local
number. Without it, prepending `961` to a French number produces `96133…` and
the message goes nowhere. A number with neither is assumed Lebanese.

---

## 6. Not built yet

- **Sending credit notes and payment reminders.** The delivery log is not
  invoice-specific, but only invoices have a send action.
- **Inbound messages.** The webhook handles delivery statuses only. Customer
  replies arrive on the same endpoint and are ignored.
- **A PDF attachment.** The template carries text; Meta supports a document
  header, but there is no invoice PDF to attach yet.
- **Opt-in tracking.** Meta requires customers to have opted in to business
  messaging. Nothing here records or enforces that, and a send to someone who
  has not opted in is rejected by Meta at send time.
- **Retry of a failed send.** Failures are recorded and visible; re-sending
  means pressing the button again.
- **Per-business templates.** The template name is global config, so every
  business sends the same one.

---

## 7. Tests

`WhatsAppTest` — number normalisation including the international case, the
exact Cloud API payload, bearer auth against the **tenant's own** credentials,
Meta's error surfaced verbatim, a failure recorded rather than lost, refusal
when unconnected (with nothing queued that could never send), drafts and
missing numbers refused, and tenant isolation.

`WhatsAppSettingsTest` — the owner can connect and an accountant cannot,
credentials are checked with Meta before being stored, a token Meta rejected is
never stored, the token is not flashed into the session and never rendered
back, test mode saves without calling Meta and says so on screen, re-checking
refreshes the display number, disconnecting keeps the delivery history, the
number pasted into the id field is refused, and connecting one business leaves
another untouched.

`WhatsAppWebhookTest` — the verification handshake, rejection of unsigned and
wrongly-signed callbacks, rejection when unconfigured, the status ladder
including `read` backfilling `delivered`, routing by `phone_number_id` with the
same message id in two businesses, and 200 for anything unrecognised.

Every test fakes HTTP with `Http::preventStrayRequests()`. **No real message is
ever sent by the suite.**
