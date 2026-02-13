/**
 * Zip the WordPress plugin folder and upload it to MongoDB GridFS.
 * Run once (or when you update the plugin): npm run plugin:upload
 * Requires: MONGODB_URI, and backend/wordpress-plugin/ai-auto-blog must exist.
 */
require("dotenv").config();
const path = require("path");
const fs = require("fs");
const mongoose = require("mongoose");
const { GridFSBucket } = require("mongodb");
const archiver = require("archiver");

const MONGODB_URI = process.env.MONGODB_URI || "mongodb://localhost:27017/autocontent-ai";
const PLUGIN_DIR = path.join(__dirname, "..", "wordpress-plugin", "ai-auto-blog");
const PLUGIN_ZIP_NAME = "autocontent-ai-plugin.zip";
const BUCKET_NAME = "plugin";

async function run() {
  if (!fs.existsSync(PLUGIN_DIR)) {
    console.error("Plugin folder not found:", PLUGIN_DIR);
    process.exit(1);
  }

  await mongoose.connect(MONGODB_URI);
  const db = mongoose.connection.db;
  const bucket = new GridFSBucket(db, { bucketName: BUCKET_NAME });

  const existing = await bucket.find({ filename: PLUGIN_ZIP_NAME }).toArray();
  for (const file of existing) {
    await bucket.delete(file._id);
    console.log("Removed previous plugin file from DB");
  }

  const uploadStream = bucket.openUploadStream(PLUGIN_ZIP_NAME, {
    contentType: "application/zip",
  });

  const archive = archiver("zip", { zlib: { level: 9 } });
  archive.pipe(uploadStream);
  archive.directory(PLUGIN_DIR, "ai-auto-blog");
  await archive.finalize();

  await new Promise((resolve, reject) => {
    uploadStream.on("finish", resolve);
    uploadStream.on("error", reject);
  });

  console.log("Plugin uploaded to MongoDB GridFS:", PLUGIN_ZIP_NAME);
  await mongoose.disconnect();
  process.exit(0);
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
