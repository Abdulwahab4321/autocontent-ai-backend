const express = require("express");
const mongoose = require("mongoose");
const { GridFSBucket } = require("mongodb");
const { auth } = require("../middleware/auth");

const router = express.Router();

const PLUGIN_ZIP_NAME = "autocontent-ai-plugin.zip";
const GRIDFS_BUCKET_NAME = "plugin";

/**
 * Download plugin from MongoDB GridFS. Plugin must be uploaded first (see scripts/uploadPluginToDb.js).
 */
router.get("/download", auth, async (req, res) => {
  const db = mongoose.connection.db;
  if (!db) {
    return res.status(503).json({ message: "Database not available." });
  }

  const bucket = new GridFSBucket(db, { bucketName: GRIDFS_BUCKET_NAME });
  const cursor = bucket.find({ filename: PLUGIN_ZIP_NAME }).sort({ uploadDate: -1 }).limit(1);
  const files = await cursor.toArray();

  if (!files.length) {
    return res.status(503).json({
      message: "Plugin package is not available. Run: npm run plugin:upload",
    });
  }

  res.attachment(PLUGIN_ZIP_NAME);
  res.setHeader("Content-Type", "application/zip");

  const readStream = bucket.openDownloadStream(files[0]._id);
  readStream.on("error", (err) => {
    if (!res.headersSent) res.status(500).json({ message: "Failed to stream plugin" });
  });
  readStream.pipe(res);
});

module.exports = { pluginRoutes: router };
