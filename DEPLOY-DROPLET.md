# Droplet par Backend Deploy karna

Aapka Droplet: **159.89.137.235**  
Pehle se port **8000** par koi backend chal raha hai. Ye naya backend **port 4000** par chalega.

---

## Sab se pehle (ek baar)

### 1. SSH se Droplet par login

Windows par PowerShell ya Git Bash open karein:

```bash
ssh root@159.89.137.235
```

(agar `root` ki jagah koi aur user use karte ho to woh username use karein; password ya SSH key se login hoga.)

---

### 2. Node.js 18+ install karein (agar abhi nahi hai)

Droplet par check karein:

```bash
node -v
```

Agar 18+ nahi hai ya `command not found` aaye to:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
node -v
```

---

### 3. PM2 install karein (process manager – restart on crash / reboot)

```bash
sudo npm install -g pm2
```

---

### 4. Git install (agar nahi hai)

```bash
sudo apt-get update
sudo apt-get install -y git
```

---

## Deploy steps (har baar deploy ke liye)

### Step 1: Backend code Droplet par lana

**Option A – Pehli baar (clone):**

```bash
cd /root
# ya: cd /home/your-user
git clone https://github.com/Abdulwahab4321/autocontent-ai-backend.git
cd autocontent-ai-backend
```

Agar repo **monorepo** hai (frontend + backend ek saath) to:

```bash
git clone https://github.com/Abdulwahab4321/autocontent-ai.git
cd autocontent-ai/backend
```

**Option B – Baad mein update (pull):**

```bash
cd /root/autocontent-ai-backend
# ya: cd /root/autocontent-ai/backend
git pull origin main
```

---

### Step 2: Dependencies install

```bash
npm ci --only=production
```

---

### Step 3: Environment variables (.env) set karein

```bash
nano .env
```

Isme **saari** variables daalein (values apni fill karein). **FRONTEND_URL** ko comma-separated rakhein taake local aur domain dono chal sakein:

```env
PORT=4000
NODE_ENV=production
JWT_SECRET=apna-strong-secret-yahan

# MongoDB Atlas
MONGODB_URI=mongodb+srv://user:pass@cluster.mongodb.net/autocontent-ai

# Local + domain dono: comma-separated (deploy ke baad domain add kar dena)
FRONTEND_URL=http://localhost:3000,https://yourdomain.com
SHOPIFY_APP_STORE_URL=https://apps.shopify.com

ADMIN_EMAILS=wahabahmed22222@gmail.com

# Stripe
STRIPE_SECRET_KEY=sk_test_...ya_sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_ID=price_...

# SMTP (forgot password / emails)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=your@gmail.com
SMTP_PASS=app-password
SMTP_FROM=your@gmail.com
```

Save: `Ctrl+O`, Enter, phir `Ctrl+X`.

---

### Step 4: PM2 se start karein

**Pehli baar:**

```bash
pm2 start ecosystem.config.js
```

**Pehle wala backend (8000) band mat karna** – ye naya app 4000 par chalega.

**Baad mein code update ke baad:**

```bash
git pull origin main
npm ci --only=production
pm2 restart autocontent-ai-api
```

---

### Step 5: PM2 ko reboot par bhi start hone ke liye set karein

```bash
pm2 startup
pm2 save
```

(jo command output mein aaye woh copy-paste karke run karein.)

---

### Step 6: Check karein

- Logs: `pm2 logs autocontent-ai-api`
- Status: `pm2 status`
- API test: browser ya Postman se  
  `http://159.89.137.235:4000/api/health`  
  Response: `{"ok":true}`

---

## Port 4000 firewall open karna

Agar droplet par firewall on hai to 4000 allow karein:

```bash
sudo ufw allow 4000
sudo ufw reload
```

---

## Short checklist (sab se pehle kya karna hai)

| Step | Command / Kaam |
|------|------------------|
| 1 | `ssh root@159.89.137.235` se login |
| 2 | `node -v` → 18+ ho, warna Node 20 install karein |
| 3 | `sudo npm install -y -g pm2` |
| 4 | Repo clone karein (monorepo ho to `backend` folder use karein) |
| 5 | `npm ci --only=production` |
| 6 | `.env` bana ke saari values set karein |
| 7 | `pm2 start ecosystem.config.js` |
| 8 | `pm2 startup` + `pm2 save` |
| 9 | `http://159.89.137.235:4000/api/health` se test |

Iske baad deploy ke liye sirf: `git pull` → `npm ci --only=production` → `pm2 restart autocontent-ai-api`.
