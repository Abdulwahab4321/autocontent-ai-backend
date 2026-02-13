# Stripe setup (AutoContent AI backend)

## 1. Environment variables

In `.env` (never commit real keys):

| Variable | Required for Stripe | Description |
|----------|---------------------|-------------|
| `STRIPE_SECRET_KEY` | Yes | Secret key from Stripe Dashboard → Developers → API keys (e.g. `sk_test_...` or `sk_live_...`) |
| `STRIPE_WEBHOOK_SECRET` | Yes (for webhook) | Signing secret from Stripe Dashboard → Webhooks → your endpoint → Reveal (e.g. `whsec_...`) |
| `STRIPE_PRICE_ID` | No | If you created a Product/Price in Stripe, set the **Price ID** (e.g. `price_xxx`). Backend will use it for Checkout instead of inline price_data. |
| `FRONTEND_URL` | Recommended | Used as default for success/cancel URLs if frontend doesn’t send them (e.g. `https://yoursite.com`) |

Without `STRIPE_SECRET_KEY`, checkout uses a mock flow (no Stripe).  
With Stripe: webhook **must** have `STRIPE_WEBHOOK_SECRET` so signature verification works.

---

## 2. Local development: Stripe cannot use localhost

Stripe’s servers call your webhook from the internet, so **you cannot use `http://localhost:4000`** as the endpoint URL. For local testing you need a tunnel.

**Option A – Stripe CLI (easiest):** Install [Stripe CLI](https://stripe.com/docs/stripe-cli), run `stripe login`, then with backend on port 4000 run:
```bash
stripe listen --forward-to localhost:4000/api/payments/webhook
```
Use the signing secret it prints as `STRIPE_WEBHOOK_SECRET` in `.env`. No Dashboard endpoint needed for local.

**Option B – ngrok:** Run `ngrok http 4000`, use the HTTPS URL in Dashboard (e.g. `https://xxx.ngrok.io/api/payments/webhook`), then set that endpoint’s signing secret in `.env`.

---

## 3. Webhook endpoint in Stripe Dashboard (production or ngrok)

1. Go to **Stripe Dashboard** → **Developers** → **Webhooks** → **Add endpoint**.
2. **Endpoint URL:** `https://your-api-domain.com/api/payments/webhook` (or ngrok URL for local)
3. **Events to send:** click “Select events” and choose **only**:

   | Event | Why |
  |-------|-----|
   | **`checkout.session.completed`** | **Required.** Fired when the customer completes payment. Backend creates Purchase + License on this event. |

   Do **not** select “Receive all events” unless you need others; the backend only handles `checkout.session.completed`.

4. After creating the endpoint, open it and **Reveal** the **Signing secret**. Put it in `.env` as `STRIPE_WEBHOOK_SECRET=whsec_...`.

---

## 4. Optional: use your Stripe product

If you created a Product and Price in Stripe:

1. In Stripe Dashboard → **Products** → open your product → copy the **Price ID** (e.g. `price_1ABC...`).
2. Set in `.env`: `STRIPE_PRICE_ID=price_xxx`.
3. Restart the backend. Checkout will use this price instead of inline `price_data`.

---

## 5. Flow summary

- Frontend: `POST /api/payments/checkout` with `successUrl`, `cancelUrl` and `Authorization: Bearer <token>`.
- Backend: Creates Stripe Checkout Session (or mock), returns `{ url, sessionId }`.
- Frontend: Redirects user to `url` (Stripe hosted page).
- User pays (or cancels). Stripe redirects to `successUrl` or `cancelUrl`.
- Stripe sends `checkout.session.completed` to your webhook URL. Backend verifies signature, then creates Purchase + License (idempotent by session ID).
