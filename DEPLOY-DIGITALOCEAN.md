# Backend DigitalOcean par Deploy karna

Backend ko **DigitalOcean App Platform** par deploy karne ke steps.

---

## 1. Tayyari

- **MongoDB:** Production ke liye [MongoDB Atlas](https://www.mongodb.com/atlas) use karein. Free tier theek hai. Atlas se connection string copy karein (e.g. `mongodb+srv://user:pass@cluster.mongodb.net/autocontent-ai`).
- **GitHub:** Code ko GitHub repo mein push karein (monorepo ho to `backend` folder ke saath).

---

## 2. DigitalOcean App Platform se deploy

### Option A: Dashboard se (sabse aasaan)

1. [DigitalOcean](https://cloud.digitalocean.com/) login karein → **Apps** → **Create App**.
2. **GitHub** choose karein, repo select karein, branch (e.g. `main`).
3. **Resource Type:** "Service" select karein.
4. **Source Directory:** `backend` likhein (taake sirf backend build ho).
5. **Dockerfile:** "Use existing Dockerfile" choose karein (path: `backend/Dockerfile`).
6. **HTTP Port:** `4000` rakhein.
7. **Environment Variables** mein ye add karein (values apni fill karein):

   | Key | Value | Type |
   |-----|--------|------|
   | `NODE_ENV` | `production` | Plain |
   | `PORT` | `4000` | Plain |
   | `JWT_SECRET` | strong random string | **Secret** |
   | `MONGODB_URI` | Atlas connection string | **Secret** |
   | `FRONTEND_URL` | https://your-frontend-domain.com | Plain |
   | `ADMIN_EMAILS` | wahabahmed22222@gmail.com | Plain |
   | `STRIPE_SECRET_KEY` | sk_live_... (agar use ho) | **Secret** |
   | `STRIPE_WEBHOOK_SECRET` | whsec_... | **Secret** |
   | `STRIPE_PRICE_ID` | price_... (optional) | Plain |
   | `SMTP_HOST` | smtp.gmail.com | Plain |
   | `SMTP_PORT` | 587 | Plain |
   | `SMTP_USER` | your-email@gmail.com | Plain |
   | `SMTP_PASS` | app password | **Secret** |
   | `SMTP_FROM` | same as SMTP_USER | Plain |

8. **Create Resources** / **Deploy** par click karein. Build complete hone ke baad app ka URL milega (e.g. `https://api-xxxxx.ondigitalocean.app`).

### Option B: App Spec (YAML) se

1. Repo root par `.do/app.yaml` hai. Usme `YOUR_GITHUB_USER/YOUR_REPO` ko apne GitHub username/repo se replace karein.
2. [doctl](https://docs.digitalocean.com/reference/doctl/how-to/install/) install karein aur `doctl auth init` se login karein.
3. App create karein:
   ```bash
   cd c:\Users\User\autocontent-ai
   doctl apps create --spec .do/app.yaml
   ```
4. Dashboard se jaakar **Environment Variables** add karein (upar wali table ke mutabiq).

---

## 3. Deploy ke baad check

- Health: `https://YOUR_APP_URL/api/health` → `{"ok":true}` aana chahiye.
- Frontend mein `FRONTEND_URL` aur API base URL sahi set karein taake CORS aur requests theek rahein.

---

## 4. Stripe Webhook (agar payments use ho rahe hain)

- Stripe Dashboard → Webhooks → Add endpoint: `https://YOUR_APP_URL/api/payments/webhook`.
- Jo events chahiye (e.g. `checkout.session.completed`) select karein.
- Signing secret copy karke App Platform env vars mein `STRIPE_WEBHOOK_SECRET` set karein.

---

## 5. Security note

- `.env` ya `.env.example` mein **real passwords / secrets commit mat karein**. Sirf DigitalOcean (ya kisi secrets manager) par env vars set karein.
- `JWT_SECRET` strong aur random rakhein (e.g. `openssl rand -base64 32`).

Agar aap **Droplet (VPS)** par deploy karna chahein (PM2 + Nginx), to bata dein, uske liye alag steps likh sakta hoon.
