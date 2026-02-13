const mongoose = require("mongoose");

const resetTokenSchema = new mongoose.Schema({
  token: { type: String, required: true, unique: true },
  userId: { type: mongoose.Schema.Types.ObjectId, ref: "User", required: true },
  expiresAt: { type: Date, required: true },
});

resetTokenSchema.index({ expiresAt: 1 }, { expireAfterSeconds: 0 }); // TTL optional

module.exports = mongoose.model("ResetToken", resetTokenSchema);
