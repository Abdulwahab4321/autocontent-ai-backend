require("dotenv").config();

module.exports = {
  port: process.env.PORT || 4000,
  nodeEnv: process.env.NODE_ENV || "development",
  jwtSecret: process.env.JWT_SECRET || "change-me-in-production",
  stripeSecretKey: process.env.STRIPE_SECRET_KEY,
  stripeWebhookSecret: process.env.STRIPE_WEBHOOK_SECRET,
  /** Per-plan Stripe Price IDs (from Stripe Dashboard Products/Prices). Used for embedded payment. */
  stripePriceIds: {
    single: process.env.STRIPE_PRICE_ID_SINGLE || process.env.STRIPE_PRICE_ID || null,
    plus: process.env.STRIPE_PRICE_ID_PLUS || null,
    expert: process.env.STRIPE_PRICE_ID_EXPERT || null,
  },
  frontendUrl: process.env.FRONTEND_URL || "http://localhost:3000",
  shopifyAppStoreUrl:
    process.env.SHOPIFY_APP_STORE_URL || "https://apps.shopify.com",
};
