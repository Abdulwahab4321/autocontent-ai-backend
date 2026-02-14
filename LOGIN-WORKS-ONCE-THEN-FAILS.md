# Pehli baar login chal jata hai, phir logout → dubara login par masla

## Do main reasons

### 1. MongoDB connection drop (free tier / idle)

Atlas **free tier** idle connections ko band kar deta hai. Pehla login tak connection warm hoti hai, thodi der baad (logout ke baad) connection drop ho chuki hoti hai. Jab dubara login karte ho to pehla request **reconnect + query** karta hai → slow ya timeout → CORS/hang.

**Backend fix (lag chuka):** `disconnected` par **auto-reconnect** add kiya. Ab connection drop hone par turant reconnect start hoga, next request pe kam hang hoga.

### 2. PM2 app crash / restart

App crash hone par PM2 restart karta hai. Us waqt login try karo to request **502** ya hang (app start ho raha hota hai, MongoDB connect ho raha hota hai).

**Check:** Jab dubara login fail ho, turant Droplet par chalao:
```bash
pm2 status
pm2 logs autocontent-ai-api --lines 30
```
- **↺ (restart count)** bar bar badh raha ho to crash ho raha hai. Logs mein error dikhega.
- **Memory** kam (e.g. 1 GB Droplet) ho to OOM se process kill ho sakta hai.

---

## Ab kya karna hai

1. **Backend deploy** – MongoDB auto-reconnect wala code: `git pull` + `pm2 restart autocontent-ai-api`.
2. **Jab phir fail ho** – `pm2 status` aur `pm2 logs` dekh kar batao: restart count badha? koi error?
3. **Nginx CORS** – Agar ab bhi CORS dikhe to `NGINX-CORS-FIX.md` ke hisaab se Nginx par CORS headers add karo.

**Short:** Pehla reason = MongoDB disconnect, iske liye auto-reconnect add ho chuka. Doosra = app crash, iske liye PM2 logs/status check karo.
