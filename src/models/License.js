const mongoose = require("mongoose");

/**
 * License: pre-generated pool (status "unused") or assigned to user (status "active").
 * One domain per license at a time; plugin activates by sending key + domain; disconnect frees the domain.
 */
const licenseSchema = new mongoose.Schema(
  {
    key: { type: String, required: true, unique: true },
    product: { type: String, default: "wordpress-plugin" },
    status: {
      type: String,
      enum: ["unused", "active", "revoked"],
      default: "unused",
    },
    userId: { type: mongoose.Schema.Types.ObjectId, ref: "User", default: null },
    domain: { type: String, default: null },
    assignedAt: { type: Date, default: null },
  },
  { timestamps: true }
);

licenseSchema.index({ userId: 1 });
licenseSchema.index({ status: 1 });
licenseSchema.set("toJSON", {
  virtuals: true,
  transform: (doc, ret) => {
    ret.id = ret._id.toString();
    ret.userId = ret.userId?.toString?.() ?? ret.userId;
    delete ret._id;
    delete ret.__v;
  },
});

module.exports = mongoose.model("License", licenseSchema);
