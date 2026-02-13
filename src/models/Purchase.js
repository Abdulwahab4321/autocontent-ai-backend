const mongoose = require("mongoose");

const purchaseSchema = new mongoose.Schema(
  {
    userId: { type: mongoose.Schema.Types.ObjectId, ref: "User", required: true },
    productId: { type: String, default: "wordpress-plugin" },
    amount: { type: Number, required: true },
    status: { type: String, default: "completed" },
    description: { type: String, default: null },
    invoiceUrl: { type: String, default: null },
    /** Stripe Checkout Session ID; used for webhook idempotency. Sparse unique index allows multiple nulls (mock checkouts). */
    stripeSessionId: { type: String, default: null },
  },
  { timestamps: true }
);

purchaseSchema.index({ userId: 1 });
// No unique index on stripeSessionId: mock checkouts use null, and multiple nulls would violate unique.
// Webhook idempotency is handled in code (findOne by stripeSessionId before create).
purchaseSchema.set("toJSON", {
  virtuals: true,
  transform: (doc, ret) => {
    ret.id = ret._id.toString();
    ret.userId = ret.userId?.toString?.() ?? ret.userId;
    delete ret._id;
    delete ret.__v;
  },
});

module.exports = mongoose.model("Purchase", purchaseSchema);
