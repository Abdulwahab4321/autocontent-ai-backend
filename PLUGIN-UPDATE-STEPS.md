# Latest plugin DB mein dalna (purana delete, naya upload)

## Jo ho chuka

- **backend/wordpress-plugin/ai-auto-blog** ko **Downloads\AutoContent AI\ai-auto-blog** se replace kar diya gaya hai (sirf plugin files, kuch aur change nahi).

## Ab tum kya karo

### 1. PC se code push karo

```bash
cd c:\Users\User\autocontent-ai
git add backend/wordpress-plugin/ai-auto-blog
git commit -m "Update plugin to latest from Downloads"
git push origin main
```

### 2. Droplet par pull + plugin upload

SSH karke:

```bash
cd ~/autocontent-ai-backend
git pull origin main
```

Droplet ki `.env` mein **local MongoDB** honi chahiye:

```env
MONGODB_URI=mongodb://localhost:27017/autocontent-ai
```

Phir **sirf plugin** DB mein update karo (purana delete, naya upload):

```bash
node scripts/uploadPluginToDb.js
```

Output mein aana chahiye:

- `Removed previous plugin file from DB` (agar pehle se plugin tha)
- `Plugin uploaded to MongoDB GridFS: autocontent-ai-plugin.zip`

### 3. Kuch aur change nahi

- Na backend restart zaroori (API chal raha rehega).
- Na koi aur collection/DB change – sirf GridFS bucket **plugin** mein zip replace hota hai.
- Dashboard se download ab naya zip hoga.

**Short:** PC se push → Droplet par pull → `node scripts/uploadPluginToDb.js` (Droplet par, local MongoDB ke saath).
