# CORS error fix – Nginx + backend

Jab backend slow ho ya 502 aaye to browser ko CORS header nahi milta, isliye "No 'Access-Control-Allow-Origin'" dikhta hai.

## 1. Backend (ho chuka)

- OPTIONS (preflight) ab sabse pehle handle hota hai – turant 204 + CORS, koi DB nahi.
- Deploy: `git pull` + `pm2 restart autocontent-ai-api`.

## 2. Nginx – CORS headers har response par (Droplet par karo)

Jab bhi Nginx 502/504 bheje, us response par bhi CORS headers chahiye. Isliye Nginx config mein **add_header** with **always** use karo.

Droplet par:

```bash
sudo nano /etc/nginx/sites-available/backend.autocontentai.co
```

**server** block ke andar, **location /** se pehle ye lines add karo:

```nginx
server {
    listen 443 ssl;
    server_name backend.autocontentai.co;

    # CORS – har response par (502/504 bhi), taake browser ko header mile
    add_header Access-Control-Allow-Origin $http_origin always;
    add_header Access-Control-Allow-Credentials "true" always;
    add_header Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;

    location / {
        proxy_pass http://127.0.0.1:4000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
    # ... baaki ssl / certbot config same
}
```

**Dhyan:** `add_header ... $http_origin always` se koi bhi origin reflect ho jata hai. Production mein agar sirf allowed origins chahiye to Nginx mein if + map se restrict karna padega; ab ke liye $http_origin theek hai taake localhost + autocontentai.co dono chal sakein.

Save karke:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 3. FRONTEND_URL (Droplet .env)

Backend ki `.env` mein localhost zaroor ho:

```env
FRONTEND_URL=http://localhost:3000,https://autocontentai.co
```

Phir `pm2 restart autocontent-ai-api`.

---

**Short:** Backend mein OPTIONS ab turant 204 + CORS deta hai. Nginx mein CORS headers "always" add karo taake 502/504 par bhi header mile.
