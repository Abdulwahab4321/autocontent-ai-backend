# AutoContent AI – Backend API Reference

**Base URL:** `http://localhost:4000/api` (or set `NEXT_PUBLIC_API_URL` in frontend)

**Auth:** For protected routes send header: `Authorization: Bearer <token>`

**Content-Type:** `application/json` for all request bodies unless noted.

---

## Health

### GET `/api/health`
No auth.  
**Body:** none  
**Response (200):**
```json
{ "ok": true }
```

---

## Auth (`/api/auth`)

### POST `/api/auth/signup`
**Body:**
```json
{
  "email": "user@example.com",
  "password": "yourpassword"
}
```
- `password` min 6 characters.

**Success (201):**
```json
{
  "user": { "id": 1, "email": "user@example.com" },
  "token": "eyJhbG..."
}
```
**Errors:**  
- 400 – missing email/password or password too short  
- 409 – `{ "message": "An account with this email already exists" }`

---

### POST `/api/auth/login`
**Body:**
```json
{
  "email": "user@example.com",
  "password": "yourpassword"
}
```
**Success (200):**
```json
{
  "user": { "id": 1, "email": "user@example.com" },
  "token": "eyJhbG..."
}
```
**Errors:**  
- 400 – missing email or password  
- 401 – `{ "message": "Invalid email or password" }`

---

### POST `/api/auth/forgot-password`
**Body:**
```json
{
  "email": "user@example.com"
}
```
**Success (200):**
```json
{
  "message": "If an account exists, you will receive a reset link."
}
```
**Error:** 400 – `{ "message": "Email is required" }`

**Note:** An email is only sent if SMTP is configured in env (`SMTP_HOST`, etc.). Otherwise the backend still returns this success message but does not send any email.

---

### POST `/api/auth/reset-password`
Used after the user clicks the link in the reset email.  
**Body:**
```json
{
  "token": "hex-token-from-email-query",
  "newPassword": "newpassword"
}
```
- `newPassword` min 8 characters.

**Success (200):**
```json
{
  "message": "Password has been reset. You can log in with your new password."
}
```
**Errors:** 400 – missing/invalid token or expired link or password too short.

---

### POST `/api/auth/change-password`
**Auth required.**  
**Body:**
```json
{
  "currentPassword": "oldpass",
  "newPassword": "newpassword",
  "confirmPassword": "newpassword"
}
```
- `newPassword` min 8 characters (backend only checks length; frontend can send confirm separately).

**Success (200):**
```json
{
  "message": "Password updated successfully"
}
```
**Errors:**  
- 400 – missing fields or new password &lt; 8 chars  
- 401 – invalid token or `{ "message": "Current password is incorrect" }`

---

## Users (`/api/users`)

### GET `/api/users/me`
**Auth required.**  
**Body:** none  
**Success (200):**
```json
{
  "id": 1,
  "email": "user@example.com",
  "role": "customer",
  "isAdmin": false
}
```

**Error:** 401 – `{ "message": "Authentication required" }` or `{ "message": "Invalid or expired token" }`

---

## Payments (`/api/payments`)

### POST `/api/payments/checkout`
**Auth required.**  
Frontend sends **successUrl**, **cancelUrl**, and optional **planId**; backend assigns licenses by plan.

**Body (all optional):**
```json
{
  "successUrl": "https://yoursite.com/dashboard?checkout=success",
  "cancelUrl": "https://yoursite.com/pricing",
  "planId": "single"
}
```
- **planId:** `"single"` (1 license), `"plus"` (3 licenses), `"expert"` (6 licenses). If omitted, 1 license is assigned.
- Full URLs. If omitted, backend uses `FRONTEND_URL` + `/dashboard?checkout=success` and `/pricing`.
- Checkout is **Stripe only**; `STRIPE_SECRET_KEY` must be set.

**Success (201):**
```json
{
  "success": true,
  "url": "https://checkout.stripe.com/...",
  "sessionId": "cs_..."
}
```
**Error:** 503 – `Payment is not configured` if `STRIPE_SECRET_KEY` is not set. 500 – Stripe or server error in `message`.

**Frontend:** Redirect user to `response.url` for Stripe Checkout; after payment Stripe redirects to `successUrl`. Webhook assigns licenses by `planId` from session metadata.

---

### GET `/api/payments/history`
**Auth required.**  
**Body:** none  
**Success (200):**
```json
[
  {
    "id": "1",
    "date": "2026-02-09T12:00:00.000Z",
    "amount": 49.99,
    "status": "completed",
    "description": "WordPress Plugin – One-time purchase",
    "invoiceUrl": null
  }
]
```
Empty array if no purchases.

**Error:** 401 – auth required.

---

### POST `/api/payments/webhook`
Used by **Stripe only** (raw body, signature verification). Frontend does **not** call this.

---

## Licenses (`/api/licenses`)

**License pool:** Licenses are pre-loaded (e.g. from CSV). On purchase, **N** unused licenses are assigned by plan: **Single** = 1, **Plus** = 3, **Expert** = 6. Each license can be connected to **one website (domain)** at a time. Plugin sends key + domain to verify; user can **disconnect** from dashboard to use the key on another site.

### GET `/api/licenses`
**Auth required.** List licenses assigned to the current user.  
**Body:** none  
**Success (200) – has licenses:**
```json
{
  "licenses": [
    {
      "id": "...",
      "userId": "...",
      "key": "ACAI...",
      "product": "wordpress-plugin",
      "status": "active",
      "domain": "mysite.com",
      "assignedAt": "2026-02-09T12:00:00.000Z",
      "createdAt": "2026-02-09T12:00:00.000Z"
    }
  ]
}
```
**Success (200) – no licenses:** `[]` or `{ "licenses": [] }`.  
**Error:** 401 – auth required.

---

### POST `/api/licenses/verify`
**No auth.** Used by the WordPress plugin for activation and periodic re-verification.  
**Body:**
```json
{
  "key": "ACAI...",
  "domain": "mysite.com"
}
```
- **key** (required): license key.  
- **domain** (required): current website domain (trimmed, lowercased for comparison).

**Behaviour:** If the license is **active** and (not yet bound or already bound to this domain): returns valid and, if not yet bound, binds the license to this domain. If the license is already bound to a **different** domain, returns invalid.

**Success (200):**
```json
{
  "valid": true,
  "status": "active",
  "product": "wordpress-plugin"
}
```
**Errors (4xx):**  
- 400 – key or domain missing.  
- 403 – license revoked, or not assigned, or already connected to another website.  
- 404 – key not found.

---

### POST `/api/licenses/:id/disconnect`
**Auth required.** Disconnect the license from the current website so it can be activated on another.  
**Params:** `id` = license id.  
**Body:** none  

**Success (200):**
```json
{
  "message": "License disconnected. You can now activate it on another website.",
  "domain": null
}
```
**Errors:** 400 – already disconnected. 404 – license not found or not your license.

---

## Plugin (`/api/plugin`)

The plugin zip is stored in **MongoDB GridFS** (bucket `plugin`). To populate or update it, run from backend: `npm run plugin:upload` (zips `wordpress-plugin/ai-auto-blog` and uploads).

### GET `/api/plugin/download`
**Auth required.**  
**Body:** none  
**Response:** Binary ZIP file (streamed from GridFS).  
Headers: `Content-Type: application/zip`, `Content-Disposition: attachment; filename="autocontent-ai-plugin.zip"`  
**Error:** 401 – auth required.  
**Frontend:** Use `fetch` with `Authorization: Bearer <token>`, then `res.blob()` and trigger download (e.g. create object URL and `<a download>`).

---

## Admin (`/api/admin`)
**All admin routes require auth + admin role.**  
Send `Authorization: Bearer <token>` (user must have `role: "admin"`).

### GET `/api/admin/users`
**Body:** none  
**Success (200):**
```json
[
  {
    "id": 1,
    "email": "user@example.com",
    "role": "customer",
    "created_at": "2026-02-09T12:00:00.000Z"
  }
]
```
**Error:** 403 – `{ "message": "Admin access required" }`

---

### GET `/api/admin/licenses`
**Body:** none  
**Success (200):**
```json
[
  {
    "id": "1",
    "key": "xxxx-xxxx-xxxx-xxxx",
    "userId": 1,
    "product": "wordpress-plugin",
    "status": "active",
    "domain": null,
    "createdAt": "2026-02-09T12:00:00.000Z"
  }
]
```

---

### GET `/api/admin/purchases`
**Body:** none  
**Success (200):**
```json
[
  {
    "id": "1",
    "user_id": 1,
    "product": "wordpress-plugin",
    "amount": 49.99,
    "payment_status": "completed",
    "created_at": "2026-02-09T12:00:00.000Z"
  }
]
```

---

### POST `/api/admin/licenses`
Issue a license manually.  
**Body (use one of):**
```json
{
  "userId": 1
}
```
or
```json
{
  "email": "user@example.com"
}
```
Optional: `"product": "wordpress-plugin"` (default).

**Success (201):**
```json
{
  "id": "2",
  "userId": 1,
  "key": "xxxx-xxxx-xxxx-xxxx",
  "product": "wordpress-plugin",
  "status": "active",
  "domain": null,
  "activatedAt": "2026-02-09T12:00:00.000Z",
  "createdAt": "2026-02-09T12:00:00.000Z"
}
```
**Errors:**  
- 400 – `{ "message": "userId or email is required" }`  
- 404 – `{ "message": "User not found for email" }`

---

### POST `/api/admin/licenses/:id/revoke`
Revoke a license.  
**Params:** `id` = license id.  
**Body:** none (or `{}`).  
**Success (200):**
```json
{
  "message": "License revoked",
  "license": { "id": "1", "status": "revoked" }
}
```
**Error:** 404 – `{ "message": "License not found" }`

---

## Summary Table

| Method | Endpoint | Auth | Body | Response |
|--------|----------|------|------|----------|
| GET | `/api/health` | No | - | `{ ok: true }` |
| POST | `/api/auth/signup` | No | `{ email, password }` | `{ user, token }` |
| POST | `/api/auth/login` | No | `{ email, password }` | `{ user, token }` |
| POST | `/api/auth/forgot-password` | No | `{ email }` | `{ message }` |
| POST | `/api/auth/change-password` | Yes | `{ currentPassword, newPassword }` | `{ message }` |
| GET | `/api/users/me` | Yes | - | `{ id, email, role, isAdmin }` |
| POST | `/api/payments/checkout` | Yes | `{ successUrl?, cancelUrl? }` | `{ success, url, sessionId? }` |
| GET | `/api/payments/history` | Yes | - | `[{ id, date, amount, status, description, invoiceUrl }]` |
| GET | `/api/licenses` | Yes | - | `{ licenses }` or `[]` |
| PATCH | `/api/licenses/:id` | Yes | `{ domains: string[] }` | `{ id, domains }` |
| POST | `/api/licenses/verify` | No | `{ key }` | `{ valid, status, product }` |
| GET | `/api/plugin/download` | Yes | - | ZIP binary |
| GET | `/api/admin/users` | Admin | - | `[{ id, email, role, created_at }]` |
| GET | `/api/admin/licenses` | Admin | - | array of licenses |
| GET | `/api/admin/purchases` | Admin | - | array of purchases |
| POST | `/api/admin/licenses` | Admin | `{ userId }` or `{ email }` | license object |
| POST | `/api/admin/licenses/:id/revoke` | Admin | - | `{ message, license }` |

---

## Error format
On error the API usually returns JSON:  
`{ "message": "Error description" }`  
Status codes: 400 (bad request), 401 (unauthorized), 403 (forbidden), 404 (not found), 409 (conflict), 500 (server error).

## Rate limits
- Auth routes: 50 requests per 15 minutes.  
- Other API: 100 requests per minute.  
When exceeded: 429 with `{ "message": "Too many requests" }` or similar.
