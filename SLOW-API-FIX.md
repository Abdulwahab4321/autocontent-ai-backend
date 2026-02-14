# API response slow – kya check karein

## 1. Sabse common: MongoDB Atlas region

Droplet **San Francisco (SFO2)** par hai. Agar MongoDB Atlas cluster **Europe** ya **Asia** mein hai to har request par 150–300ms+ extra lag sakta hai.

**Fix:** Atlas cluster ko **US West** (ya Droplet ke kareeb) region mein rakhein.

- **MongoDB Atlas** → **Database** → apna cluster → **Edit** (ya Create new cluster).
- **Cloud Provider & Region:** **AWS** (ya Google Cloud) + **US West** (e.g. **Oregon / us-west-2** ya **N. California**). SFO2 ke kareeb = kam latency.
- Agar cluster change karna mushkil ho to **naya cluster** US West mein banao, data migrate karo, phir connection string update karo.

---

## 2. Connection string options

`.env` mein `MONGODB_URI` ke end par ye add karke try karein (already same options ho to skip):

```
?retryWrites=true&w=majority
```

Agar pehle se hai to theek. Nahi to poora URI aisa ho sakta hai:

```
mongodb+srv://user:pass@cluster.xxxxx.mongodb.net/autocontent-ai?retryWrites=true&w=majority
```

---

## 3. Kaunse request slow hain

- **Sirf pehla request** slow, baad mein theek → app/DB “warm-up” (normal).
- **Har request** slow → zyada chance **Atlas region** ya network latency ka hai.
- **Koi specific endpoint** (e.g. licenses, history) slow → us route ki queries / indexes check karein.

---

## 4. Droplet / Nginx

- Droplet size chota ho (e.g. 1 GB RAM) to heavy load par thoda slow ho sakta hai; region fix ke baad bhi issue ho to size upgrade consider karein.
- Nginx usually itna delay nahi deta; pehle Atlas region fix karein.

---

**Short:** Pehle **Atlas cluster ka region** US West (Droplet ke kareeb) karein. Phir bhi slow ho to batao kaun sa endpoint slow hai.
