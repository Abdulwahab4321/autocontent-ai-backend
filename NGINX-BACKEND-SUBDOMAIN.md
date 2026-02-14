# backend.autocontentai.co – Nginx + SSL setup

DNS: `backend.autocontentai.co` → `159.89.137.235` ✓

Ab Droplet par ye steps karo (SSH: `ssh root@159.89.137.235` ya `ssh root@backend.autocontentai.co`).

---

## Step 1: Nginx install (agar nahi hai)

```bash
sudo apt update
sudo apt install nginx -y
```

---

## Step 2: Naya site config – backend subdomain

```bash
sudo nano /etc/nginx/sites-available/backend.autocontentai.co
```

Isme ye daalo (copy-paste):

```nginx
server {
    listen 80;
    server_name backend.autocontentai.co;

    location / {
        proxy_pass http://127.0.0.1:4000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Save: Ctrl+O, Enter, Ctrl+X.

---

## Step 3: Site enable karo

```bash
sudo ln -sf /etc/nginx/sites-available/backend.autocontentai.co /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Step 4: SSL (HTTPS) – Let's Encrypt

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d backend.autocontentai.co
```

Email daalo, terms accept karo. Certbot config update karke HTTPS enable kar dega.

---

## Step 5: Test

- Browser: **https://backend.autocontentai.co/api/health** → `{"ok":true}`

---

## Step 6: Frontend + Stripe

- **Frontend .env:** `NEXT_PUBLIC_API_URL=https://backend.autocontentai.co`
- **Stripe Webhook:** `https://backend.autocontentai.co/api/payments/webhook` (naya endpoint add karke us endpoint ka signing secret backend `.env` mein `STRIPE_WEBHOOK_SECRET`)
