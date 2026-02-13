/**
 * Seed license pool from CSV.
 * Usage: node scripts/seedLicenses.js [path-to-csv]
 * Default: data/license_pool.csv (relative to backend root)
 * CSV format: header "license_key", one key per line.
 */
require("dotenv").config({ path: require("path").join(__dirname, "..", ".env") });
const fs = require("fs");
const path = require("path");
const { connectDB } = require("../src/db");
const License = require("../src/models/License");

const DEFAULT_CSV = path.join(__dirname, "..", "data", "license_pool.csv");

async function main() {
  const csvPath = process.argv[2] || DEFAULT_CSV;
  if (!fs.existsSync(csvPath)) {
    console.error("CSV not found:", csvPath);
    console.error("Usage: node scripts/seedLicenses.js [path-to-csv]");
    process.exit(1);
  }

  const content = fs.readFileSync(csvPath, "utf8");
  const lines = content.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const header = lines[0];
  const keyColumn = "license_key";
  if (!header || !header.toLowerCase().includes(keyColumn)) {
    console.error("CSV must have header containing 'license_key'. Got:", header);
    process.exit(1);
  }

  const keys = [];
  for (let i = 1; i < lines.length; i++) {
    const key = lines[i].trim();
    if (key && key.length > 0) keys.push(key);
  }

  if (keys.length === 0) {
    console.error("No license keys found in CSV.");
    process.exit(1);
  }

  await connectDB();

  let inserted = 0;
  let skipped = 0;
  for (const key of keys) {
    const exists = await License.findOne({ key });
    if (exists) {
      skipped++;
      continue;
    }
    await License.create({
      key,
      product: "wordpress-plugin",
      status: "unused",
      userId: null,
      domain: null,
      assignedAt: null,
    });
    inserted++;
  }

  console.log(`Done. Inserted: ${inserted}, Skipped (already exist): ${skipped}`);
  process.exit(0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
