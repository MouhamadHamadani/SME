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
| A **verified WhatsApp Business number** | sending at all | `whatsapp:connect` |
| A **permanent access token** | sending at all | `whatsapp:connect` (stored encrypted) |
| An **approved message template** | sending at all | `WHATSAPP_INVOICE_TEMPLATE` |
| App secret + verify token | delivery receipts | `.env` |

> **Confirm the API shape against current Meta docs before going live.** The
> endpoint (`/{phone-number-id}/messages`), the template payload, and the
> webhook envelope in this code follow the Cloud API as of the pinned
> `WHATSAPP_API_VERSION`. Meta changes these between versions, and a wrong
> shape fails at send time, not at deploy time.

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

The invoice screen says **"Test mode: messages are written to the log and
nothing is sent"** whenever the driver is not `cloud`. Believing an invoice was
delivered when nothing left the machine is the worst failure this feature has,
so it is stated on screen rather than left to the reader's memory.

---

## 3. Credentials are per business

Each tenant connects its **own** Meta number:

```bash
php artisan whatsapp:connect arzclinic --phone-number-id=1234567890
# prompts for the token, hidden

php artisan whatsapp:connect arzclinic --show
```

A platform-wide token would let one business's invoices go out from another's
number. The access token is **encrypted at rest** (`Crypt::encryptString`) in
the tenant's settings — it is a bearer credential that can send as the
business. The command prompts for it rather than taking an argument, so it
does not land in shell history or a process list.

A token encrypted under a rotated `APP_KEY` is treated as **not connected**
rather than sent as a garbage bearer token.

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

`WhatsAppWebhookTest` — the verification handshake, rejection of unsigned and
wrongly-signed callbacks, rejection when unconfigured, the status ladder
including `read` backfilling `delivered`, routing by `phone_number_id` with the
same message id in two businesses, and 200 for anything unrecognised.

Every test fakes HTTP with `Http::preventStrayRequests()`. **No real message is
ever sent by the suite.**
