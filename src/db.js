const mongoose = require("mongoose");

const MONGODB_URI = process.env.MONGODB_URI || "mongodb://localhost:27017/autocontent-ai";

async function connectDB() {
  try {
    await mongoose.connect(MONGODB_URI);
    console.log("MongoDB connected");
    // Drop old stripeSessionId unique index if present (allowed only one null; caused E11000 on mock checkout).
    const db = mongoose.connection.db;
    if (db) {
      await db.collection("purchases").dropIndex("stripeSessionId_1").catch(() => {});
    }
  } catch (err) {
    console.error("MongoDB connection error:", err.message);
    throw err;
  }
}

module.exports = { connectDB };
