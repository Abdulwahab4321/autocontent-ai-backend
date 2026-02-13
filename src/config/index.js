require("dotenv").config();

module.exports = {
  port: process.env.PORT || 4000,
  nodeEnv: process.env.NODE_ENV || "development",
  jwtSecret: process.env.JWT_SECRET || "change-me-in-production",
  stripeSecretKey: process.env.STRIPE_SECRET_KEY,
  stripeWebhookSecret: process.env.STRIPE_WEBHOOK_SECRET,
  /** Stripe Price ID (e.g. price_xxx). If set, Checkout uses this instead of inline price_data. */
  stripePriceId: process.env.STRIPE_PRICE_ID || null,
  frontendUrl: process.env.FRONTEND_URL || "http://localhost:3000",
  shopifyAppStoreUrl:
    process.env.SHOPIFY_APP_STORE_URL || "https://apps.shopify.com",
};
