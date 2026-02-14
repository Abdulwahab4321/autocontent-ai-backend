const mongoose = require("mongoose");

const MONGODB_URI = process.env.MONGODB_URI || "mongodb://localhost:27017/autocontent-ai";

const options = {
  maxPoolSize: 10,
  serverSelectionTimeoutMS: 15000,
  socketTimeoutMS: 45000,
  connectTimeoutMS: 15000,
};

async function connectDB() {
  try {
    await mongoose.connect(MONGODB_URI, options);
    console.log("MongoDB connected");
    const conn = mongoose.connection;
    conn.on("error", (err) => console.error("[MongoDB] connection error:", err.message));
    conn.on("disconnected", () => {
      console.warn("[MongoDB] disconnected – reconnecting…");
      mongoose.connect(MONGODB_URI, options).catch((err) => {
        console.error("[MongoDB] reconnect failed:", err.message);
      });
    });
    // Drop old stripeSessionId unique index if present (allowed only one null; caused E11000 on mock checkout).
    const db = conn.db;
    if (db) {
      await db.collection("purchases").dropIndex("stripeSessionId_1").catch(() => {});
    }
  } catch (err) {
    console.error("MongoDB connection error:", err.message);
    throw err;
  }
}

module.exports = { connectDB };
