# Server (VPS / PM2) par backend error fix

Jab **local** pe sab theek ho aur **server** pe `Startup failed` ya MongoDB error aaye, ye steps check karo.

---

## 1. Sabse pehle: Server ka IP MongoDB Atlas mein add karo

Local pe tumhara PC ka IP whitelist mein tha, isliye wahan chal raha tha. **Server ka public IP alag hai** – use Atlas pe add karna zaroori hai.

1. **Server ka public IP dekho** (same server pe run karo):
   ```bash
   curl -s ifconfig.me
   ```
   Ya DigitalOcean Droplet dashboard → **Networking** → Public IP.

2. **MongoDB Atlas** → https://cloud.mongodb.com  
   - Apna project → **Network Access** (left sidebar)  
   - **Add IP Address**  
   - **Add Current IP Address** mat use karo (wo tumhare browser/PC ka IP add karega).  
   - **"Allow Access from Anywhere"** choose karo **ya** manually server ka IP daalo (e.g. `159.89.137.235/32`).  
   - Confirm → 1–2 min wait karo.

3. Phir server pe restart:
   ```bash
   pm2 restart autocontent-ai-api --kill-timeout 5000
   pm2 logs autocontent-ai-api --lines 30
   ```

Agar logs mein ab bhi `Could not connect to any servers in your MongoDB Atlas cluster` aaye, to double-check Atlas **Network Access** mein server IP (ya 0.0.0.0/0) add hai.

---

## 2. Server pe .env sahi hai?

SSH se server pe:

```bash
cd ~/autocontent-ai-backend
nano .env
```

Check karo:

| Variable        | Zaroori | Example |
|----------------|--------|---------|
| `PORT`         | Haan   | `4000` |
| `NODE_ENV`     | Haan   | `production` |
| `MONGODB_URI`  | Haan   | `mongodb+srv://user:pass@cluster.mongodb.net/autocontent-ai` |
| `JWT_SECRET`   | Haan   | koi strong random string |
| `FRONTEND_URL` | Haan   | `https://www.autocontentai.co` (production site) |

- **MONGODB_URI** mein password special characters agar hain to URL-encoded hona chahiye (e.g. `@` → `%40`).
- **FRONTEND_URL** bilkul wahi domain jo browser mein frontend ke liye use karte ho (CORS ke liye).

Save: `Ctrl+O`, Enter, `Ctrl+X`. Phir:

```bash
pm2 restart autocontent-ai-api --kill-timeout 5000
pm2 logs autocontent-ai-api --lines 30
```

---

## 3. PM2 sahi directory se start ho raha hai?

```bash
pm2 show autocontent-ai-api
```

- **exec cwd** = `~/autocontent-ai-backend` (ya jahan backend code hai) hona chahiye.
- **script** = `server.js` ya `npm start` (aur start command jahan backend folder hai wahi se run ho).

Agar galat directory se start ho raha ho to:

```bash
pm2 delete autocontent-ai-api
cd ~/autocontent-ai-backend
pm2 start server.js --name autocontent-ai-api
pm2 save
```

---

## 4. Logs se exact error dekho

```bash
pm2 logs autocontent-ai-api --lines 50
```

- **MongooseServerSelectionError** / **Could not connect to any servers** → Atlas Network Access (step 1).
- **EADDRINUSE** → PORT 4000 pe pehle se kuch chal raha hai; ya to us process ko band karo ya .env mein dusra PORT do.
- **JWT_SECRET** / **config** error → .env mein JWT_SECRET set karo.

---

## 5. Health check

Server pe hi test karo:

```bash
curl -s http://localhost:4000/api/health
```

`{"ok":true}` aana chahiye. Agar yahan bhi error aaye to problem backend/MongoDB hai; agar yahan OK hai to Nginx/firewall check karo (port 4000 bahar se open hai ya nahi).

---

**Short:** 20 min se jo error aa raha hai wo **zyada chance MongoDB Atlas ka server IP whitelist** hai. Pehle step 1 (Atlas Network Access mein server IP add karo) zaroor karo, phir `pm2 restart` + `pm2 logs` se error message share karo agar ab bhi issue ho.
