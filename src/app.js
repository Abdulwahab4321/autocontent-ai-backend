const express = require("express");
const cors = require("cors");
const rateLimit = require("express-rate-limit");
const { authRoutes } = require("./routes/authRoutes");
const { userRoutes } = require("./routes/userRoutes");
const { paymentRoutes, stripeWebhookHandler } = require("./routes/paymentRoutes");
const { licenseRoutes } = require("./routes/licenseRoutes");
const { pluginRoutes } = require("./routes/pluginRoutes");
const { adminRoutes } = require("./routes/adminRoutes");

const app = express();

app.use(cors({ origin: process.env.FRONTEND_URL || "http://localhost:3000" }));

// PRD: Rate limiting – apply to auth and sensitive routes
const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 50,
  message: { message: "Too many attempts, try again later" },
});
const apiLimiter = rateLimit({
  windowMs: 1 * 60 * 1000,
  max: 100,
  message: { message: "Too many requests" },
});

// Stripe webhook must receive raw body for signature verification (PRD: Stripe webhook verification)
app.post(
  "/api/payments/webhook",
  express.raw({ type: "application/json" }),
  stripeWebhookHandler
);

app.use(express.json());

app.use("/api/auth", authLimiter, authRoutes);
app.use("/api/users", apiLimiter, userRoutes);
app.use("/api/payments", apiLimiter, paymentRoutes);
app.use("/api/licenses", apiLimiter, licenseRoutes);
app.use("/api/plugin", apiLimiter, pluginRoutes);
app.use("/api/admin", apiLimiter, adminRoutes);

app.get("/api/health", (_, res) => res.json({ ok: true }));

module.exports = app;
