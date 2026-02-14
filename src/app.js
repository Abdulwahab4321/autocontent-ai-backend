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

// Nginx/reverse proxy sends X-Forwarded-For; required for express-rate-limit behind proxy.
// 1 = trust first proxy; true = trust all (use when behind Nginx + load balancer).
app.set("trust proxy", true);

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

// Preflight (OPTIONS) – turant 204 + CORS, koi DB/route nahi (CORS error fix)
app.use((req, res, next) => {
  if (req.method !== "OPTIONS") return next();
  const origin = req.headers.origin;
  if (origin && allowedOrigins.includes(origin)) {
    res.setHeader("Access-Control-Allow-Origin", origin);
  }
  res.setHeader("Access-Control-Allow-Credentials", "true");
  res.setHeader("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
  res.setHeader("Access-Control-Max-Age", "86400");
  return res.status(204).end();
});

// Request timeout: agar 25s mein response nahi bheja to 504 bhejo (connection hang na ho)
const REQUEST_TIMEOUT_MS = 25000;
app.use((req, res, next) => {
  const t = setTimeout(() => {
    if (!res.headersSent) {
      res.status(504).json({ message: "Request timed out. Please try again." });
    }
  }, REQUEST_TIMEOUT_MS);
  res.on("finish", () => clearTimeout(t));
  res.on("close", () => clearTimeout(t));
  next();
});

// PRD: Rate limiting – apply to auth and sensitive routes.
// validate.xForwardedForHeader: false – Nginx sends X-Forwarded-For; we set trust proxy, so skip lib check.
const rateLimitValidate = { xForwardedForHeader: false };
const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 50,
  message: { message: "Too many attempts, try again later" },
  validate: rateLimitValidate,
});
const apiLimiter = rateLimit({
  windowMs: 1 * 60 * 1000,
  max: 100,
  message: { message: "Too many requests" },
  validate: rateLimitValidate,
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
