# Kabhi API slow, kabhi error – kya check karein

## Possible causes

| Cause | Kabhi slow | Kabhi error |
|-------|------------|-------------|
| **MongoDB Atlas** region door hai | ✓ Har request slow | Timeout par error |
| **MongoDB connection** drop (idle / network) | ✓ First request after drop slow | Connection errors |
| **Nginx** timeout kam hai | — | 502 / 504 jab backend late respond kare |
| **PM2** app restart (crash / OOM) | ✓ First request after restart slow | 502 / connection refused |
| **Droplet** RAM kam (1 GB) | ✓ Under load slow | Process kill, restart |

---

## 1. Droplet par abhi check karo

```bash
# App kitni baar restart hua?
pm2 status
# ↺ column dekhna – zyada (e.g. 50+) = crash loop

# Recent errors
pm2 logs autocontent-ai-api --lines 50 --err
```

Agar **restart count** bar bar badh raha hai to app crash ho raha hai (memory ya uncaught error). Logs mein reason dikhega.

---

## 2. Nginx timeout badhao

Jab backend late respond kare to Nginx 60s (default) ke baad 504 de sakta hai. Backend ke liye timeout zyada rakho.

Droplet par:

```bash
sudo nano /etc/nginx/sites-available/backend.autocontentai.co
```

`location /` block ke andar `proxy_pass` ke upar ye add karo:

```nginx
location / {
    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;
    proxy_pass http://127.0.0.1:4000;
    # ... baaki headers same
}
```

Phir:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 3. MongoDB Atlas

- **Region:** Cluster **US West** (Droplet SFO ke kareeb) ho to latency kam.
- **Network Access:** Droplet IP whitelist mein ho.
- **Atlas dashboard:** Metrics / Logs mein connection errors ya high latency to nahi.

---

## 4. Backend mein jo change kiya

- **db.js:** `socketTimeoutMS`, `connectTimeoutMS` badhaye taake slow network par kam timeout error aaye.
- **Connection events:** `disconnected` / `error` log ho rahe hain to PM2 logs mein dikhenge.

---

## 5. Agar error message ya screenshot ho

- **502 Bad Gateway** → Backend down ya crash; `pm2 status` aur `pm2 logs` dekho.
- **504 Gateway Timeout** → Backend ne 60s ke andar respond nahi kiya; Nginx timeout badhao (upar) ya slow query/Atlas check karo.
- **ECONNRESET / socket hang up** → Connection drop; Atlas region + connection options (already updated) theek hai to network / Atlas status check karo.

**Short:** PM2 logs + restart count dekho, Nginx timeout 60s karo, Atlas US West rakho. Jo exact error dikhe woh batao to us hisaab se next step bata sakte hain.
