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

// Allow multiple origins: set FRONTEND_URL to "http://localhost:3000,https://autocontentai.co"
// Response must send exactly ONE origin (browser rejects comma-separated).
const allowedOrigins = (process.env.FRONTEND_URL || "http://localhost:3000")
  .split(",")
  .map((s) => s.trim())
  .filter(Boolean);
app.use(
  cors({
    origin(origin, callback) {
      if (!origin || allowedOrigins.includes(origin)) {
        callback(null, true);
      } else {
        callback(null, false);
      }
    },
    credentials: true,
  })
);

// Ensure CORS header on every response (including errors) so browser sees it
app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (origin && allowedOrigins.includes(origin)) {
    res.setHeader("Access-Control-Allow-Origin", origin);
  }
  res.setHeader("Access-Control-Allow-Credentials", "true");
  next();
});

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
