# License pool (AutoContent AI)

Licenses are **pre-generated** and stored in the database with status `unused`. On purchase (or admin issue), one unused license is **assigned** to the user (status `active`). Each license can be connected to **one website (domain)** at a time.

## Seed the pool from CSV

1. Place your CSV with a header row containing `license_key` and one key per line, e.g.:
   ```csv
   license_key
   ACAI...
   ACAI...
   ```
2. From the backend directory run:
   ```bash
   npm run seed:licenses
   ```
   This uses `data/license_pool.csv` by default. To use another file:
   ```bash
   node scripts/seedLicenses.js "C:\path\to\your\file.csv"
   ```
3. Ensure MongoDB is running and `MONGODB_URI` is set in `.env`.

The script **skips** keys that already exist, so you can re-run it safely to add new keys.

## Flow

- **Purchase:** Backend assigns one `unused` license to the user and returns its key. If no unused license exists, checkout returns 503.
- **Plugin activation:** Plugin sends `key` + `domain` to `POST /api/licenses/verify`. If the license is active and (unbound or already bound to this domain), the backend returns valid and binds the domain if needed. If bound to another domain, returns invalid.
- **Disconnect:** User can disconnect from the dashboard (`POST /api/licenses/:id/disconnect`). The license is then free to be activated on another domain.
- **Re-verification:** Plugin should re-verify periodically (e.g. every 24 hours). If the license is revoked or disconnected, the next verify returns invalid and the plugin should deactivate.
