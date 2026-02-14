# Same domain par do backends (AutoContent + purana backend)

Droplet par ek hi domain hai, purana backend (e.g. port 8000) pehle se chal raha hai. AutoContent backend (port 4000) bhi isi domain par run karna hai.

Do options:

---

## Option 1: Subdomain (recommended – sabse saaf)

- **Purana backend:** `domain.com` ya `app.domain.com` → port 8000  
- **AutoContent API:** `api.autocontentai.co` (ya `autocontent.domain.com`) → port 4000  

Nginx mein **do server blocks**: ek purane backend ke liye, ek AutoContent ke liye.  
DNS mein `api.autocontentai.co` ka A record Droplet IP par point karo.

**Example Nginx config (sirf AutoContent wala block):**

```nginx
# AutoContent AI backend – port 4000
server {
    listen 80;
    server_name api.autocontentai.co;   # apna subdomain yahan

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

SSL ke liye (Let’s Encrypt):

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d api.autocontentai.co
```

Phir frontend / Stripe mein API URL: **`https://api.autocontentai.co`**  
Stripe webhook: **`https://api.autocontentai.co/api/payments/webhook`**

---

## Option 2: Same domain, path se (e.g. /autocontent-api)

Agar subdomain nahi lena aur **ek hi domain** par dono chalane hon:

- `domain.com/` → purana backend (8000)  
- `domain.com/autocontent-api/` → AutoContent backend (4000)  

**Nginx example (existing server block mein add):**

```nginx
# Purana backend (jaise pehle se)
location / {
    proxy_pass http://127.0.0.1:8000;
    # ... apne headers
}

# AutoContent backend – path /autocontent-api
location /autocontent-api/ {
    rewrite ^/autocontent-api(.*)$ $1 break;
    proxy_pass http://127.0.0.1:4000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Is case mein:

- API base URL: **`https://domain.com/autocontent-api`**  
- Health: `https://domain.com/autocontent-api/api/health`  
- Stripe webhook: **`https://domain.com/autocontent-api/api/payments/webhook`**  

Frontend `.env` mein:

```env
NEXT_PUBLIC_API_URL=https://domain.com/autocontent-api
```

---

## Steps (subdomain – Option 1)

1. **DNS:** `api.autocontentai.co` → Droplet IP (A record).  
2. **Nginx:** Naya server block banao (upar wala) `server_name api.autocontentai.co;`.  
3. **SSL:** `sudo certbot --nginx -d api.autocontentai.co`  
4. **Backend .env:** kuch change nahi (app port 4000 par hi).  
5. **Frontend:** `NEXT_PUBLIC_API_URL=https://api.autocontentai.co`  
6. **Stripe:** Webhook URL = `https://api.autocontentai.co/api/payments/webhook`, naya signing secret backend `.env` mein.

Dono backends same Droplet par, same domain family par – Nginx sirf request path/subdomain ke hisaab se dono ko run kara raha hai.
