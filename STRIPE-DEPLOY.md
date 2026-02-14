# Stripe: Deploy ke baad payment / invoice / license nahi dikhna

## Problem kya hai?

- User Stripe se pay karta hai → redirect dashboard par hota hai ✓  
- Lekin **invoice history** aur **license** nahi dikhte.  
- **History API** 200 deti hai par response **empty** `[]`.

## Root cause

**Purchase** aur **License** backend sirf **Stripe webhook** se banata hai (`checkout.session.completed`).  
Agar Stripe Dashboard mein webhook URL **localhost** ya **ngrok** par hai, to **deployed backend** (Droplet) ko event milta hi nahi → koi Purchase/License create nahi hota → history/license empty.

## Fix (zaroor karo)

### 1. Stripe Dashboard mein naya Webhook endpoint (deployed backend ke liye)

1. **Stripe Dashboard** → **Developers** → **Webhooks** → **Add endpoint**.
2. **Endpoint URL** daalo:
   - Agar API ka **HTTPS** URL hai (e.g. domain + SSL):  
     `https://api.autocontentai.co/api/payments/webhook`
   - Abhi sirf IP hai to Stripe **HTTPS** maangta hai; pehle API ke liye domain + SSL set karo (e.g. `api.autocontentai.co` + Nginx + Let’s Encrypt), phir yahi format use karo.
3. **Events** → "Select events" → sirf **`checkout.session.completed`** choose karo.
4. Save karo.
5. Naye endpoint par jaakar **Signing secret** "Reveal" karo (e.g. `whsec_...`).

### 2. Droplet par naya webhook secret set karo

Droplet par backend `.env` mein **yahi naya** secret use karo (purana local/ngrok wala hata do deployed ke liye):

```bash
nano ~/autocontent-ai-backend/.env
```

Line update karo:

```env
STRIPE_WEBHOOK_SECRET=whsec_xxxx   # Stripe Dashboard se naya signing secret
```

Save karke:

```bash
pm2 restart autocontent-ai-api
```

### 3. Success / cancel URL (redirect sahi ho)

Checkout banate waqt `success_url` / `cancel_url` sahi hon. Backend `FRONTEND_URL` ya request body se leta hai.  
Droplet `.env` mein:

```env
FRONTEND_URL=https://autocontentai.co
```

Agar frontend request mein `successUrl` / `cancelUrl` bhejta hai to woh use hota hai; warna `FRONTEND_URL` se banega. Dono jagah deployed frontend URL hona chahiye taake payment ke baad sahi dashboard par redirect ho.

---

## Summary

| Cheez              | Kya karna hai |
|--------------------|----------------|
| Webhook URL        | Stripe Dashboard mein **deployed backend** ka **HTTPS** URL add karo (e.g. `https://api.autocontentai.co/api/payments/webhook`). |
| STRIPE_WEBHOOK_SECRET | Isi naye endpoint ka **Signing secret** Droplet `.env` mein daalo. |
| FRONTEND_URL       | Deployed frontend URL (e.g. `https://autocontentai.co`) taake redirect sahi ho. |

**Note:** Stripe webhooks ke liye **HTTPS** zaroori hai. Agar abhi API sirf `http://159.89.137.235:4000` par hai to pehle API subdomain (e.g. `api.autocontentai.co`) + SSL set karo, phir Stripe mein wohi URL use karo.
