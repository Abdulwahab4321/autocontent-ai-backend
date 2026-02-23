const express = require("express");
const mongoose = require("mongoose");
const config = require("../config");
const User = require("../models/User");
const Purchase = require("../models/Purchase");
const License = require("../models/License");
const { auth } = require("../middleware/auth");
const crypto = require("crypto");

/**
 * Assign one unused license from the pool to the user. Returns the license doc or null if none available.
 */
async function assignLicenseFromPool(userId) {
  const license = await License.findOneAndUpdate(
    { status: "unused" },
    {
      $set: {
        userId,
        status: "active",
        assignedAt: new Date(),
      },
    },
    { returnDocument: "after" }
  );
  return license;
}

/** Licenses per plan (matches pricing: Single=1, Plus=3, Expert=6). */
const PLAN_LICENSES = { single: 1, plus: 3, expert: 6 };

/**
 * Assign up to `count` unused licenses from the pool to the user.
 * Returns array of assigned license docs (may be fewer than count if pool is low).
 */
async function assignLicensesFromPool(userId, count) {
  const assigned = [];
  for (let i = 0; i < count; i++) {
    const license = await assignLicenseFromPool(userId);
    if (!license) break;
    assigned.push(license);
  }
  return assigned;
}

const router = express.Router();

const PRODUCT_ID = "wordpress-plugin";
const DEFAULT_AMOUNT = 49.99;

/** Plan prices in USD cents for Stripe (Single $39, Plus $99, Expert $149). */
const PLAN_AMOUNTS_CENTS = { single: 3900, plus: 9900, expert: 14900 };

function hashPassword(password) {
  return crypto.scryptSync(password, "autocontent-salt", 64).toString("hex");
}

async function findOrCreateUserByEmail(email) {
  const trimmed = String(email).trim().toLowerCase();
  let user = await User.findOne({ email: trimmed });
  if (user) return user;
  user = await User.create({
    email: trimmed,
    passwordHash: hashPassword(crypto.randomBytes(24).toString("hex")),
    role: "customer",
    isAdmin: false,
  });
  return user;
}

/**
 * Build Stripe Checkout Session line_items. Always uses plan-specific price (Single $39, Plus $99, Expert $149).
 * STRIPE_PRICE_ID is not used here so the correct amount always shows on Stripe Checkout.
 */
function getStripeLineItems(planId) {
  const normalized = planId && typeof planId === "string" ? planId.trim().toLowerCase() : null;
  const amountCents =
    normalized && PLAN_AMOUNTS_CENTS[normalized] ? PLAN_AMOUNTS_CENTS[normalized] : 3900;
  const planLabels = { single: "Single", plus: "Plus", expert: "Expert" };
  const planLabel = normalized && planLabels[normalized] ? planLabels[normalized] : "Single";
  const licenseCount = normalized && PLAN_LICENSES[normalized] ? PLAN_LICENSES[normalized] : 1;

  return [
    {
      price_data: {
        currency: "usd",
        unit_amount: amountCents,
        product_data: {
          name: `AutoContent AI – WordPress Plugin (${planLabel})`,
          description: `${licenseCount} license(s). One-time payment. Lifetime updates & support.`,
        },
      },
      quantity: 1,
    },
  ];
}

/**
 * Get Stripe Price ID for a plan from config (Product/Price IDs from Stripe Dashboard).
 */
function getPriceIdForPlan(planId) {
  const normalized = planId && typeof planId === "string" ? planId.trim().toLowerCase() : null;
  if (!normalized || !PLAN_LICENSES[normalized]) return null;
  const priceIds = config.stripePriceIds || {};
  return priceIds[normalized] || null;
}

/**
 * Resolve Stripe Price (with amount) from config. Config can be Price ID (price_xxx) or Product ID (prod_xxx).
 * If Product ID, fetches first active one-time price for that product.
 */
async function resolvePriceForPlan(stripe, planId) {
  const raw = getPriceIdForPlan(planId);
  if (!raw) return null;
  const trimmed = String(raw).trim();
  if (trimmed.startsWith("price_")) {
    const price = await stripe.prices.retrieve(trimmed);
    return price && price.unit_amount ? price : null;
  }
  if (trimmed.startsWith("prod_")) {
    const list = await stripe.prices.list({ product: trimmed, active: true });
    const oneTime = list.data.find((p) => p.type === "one_time");
    const price = oneTime || list.data[0];
    return price && price.unit_amount ? price : null;
  }
  return null;
}

/**
 * Get or create a Stripe Customer by email. Links payments to Stripe Dashboard → Customers.
 * Uses idempotency key so concurrent create-payment-intent calls for same email create only one Customer.
 */
async function getOrCreateStripeCustomer(stripe, email) {
  const trimmed = String(email || "").trim().toLowerCase();
  if (!trimmed) return null;
  const list = await stripe.customers.list({ email: trimmed, limit: 1 });
  if (list.data.length > 0) return list.data[0].id;
  const idempotencyKey = "customer_" + trimmed.replace(/[^a-z0-9@._-]/gi, "_");
  try {
    const customer = await stripe.customers.create(
      { email: trimmed },
      { idempotencyKey }
    );
    return customer.id;
  } catch (err) {
    if (err.statusCode === 409) {
      const again = await stripe.customers.list({ email: trimmed, limit: 1 });
      if (again.data.length > 0) return again.data[0].id;
    }
    throw err;
  }
}

/**
 * Create Stripe PaymentIntent for embedded checkout.
 * Uses Stripe Price ID (price_xxx) or Product ID (prod_xxx); if neither set, falls back to plan amounts.
 * idempotencyKey: when provided, duplicate requests return the same PaymentIntent (avoids double PI on Strict Mode).
 * userEmail: optional; when provided, creates/finds Stripe Customer and links to PaymentIntent (shows in Stripe Dashboard).
 * Returns { clientSecret, amount, currency } for frontend.
 */
async function createPaymentIntentForPlan(userId, planId, idempotencyKey, userEmail) {
  const Stripe = require("stripe");
  const stripe = new Stripe(config.stripeSecretKey);
  const normalized = planId && typeof planId === "string" ? planId.trim().toLowerCase() : null;
  if (!normalized || !PLAN_LICENSES[normalized]) {
    const err = new Error("Invalid plan. Use single, plus, or expert.");
    err.code = "INVALID_PLAN";
    throw err;
  }

  let amount;
  let currency = "usd";

  const priceIds = config.stripePriceIds || {};
  const configValue = priceIds[normalized];

  if (configValue && String(configValue).trim()) {
    const price = await resolvePriceForPlan(stripe, planId);
    if (price) {
      amount = price.unit_amount;
      currency = (price.currency || "usd").toLowerCase();
    }
  }

  if (amount == null) {
    amount = PLAN_AMOUNTS_CENTS[normalized] ?? 3900;
  }

  let stripeCustomerId = null;
  if (userEmail && String(userEmail).trim()) {
    stripeCustomerId = await getOrCreateStripeCustomer(stripe, userEmail);
  }

  const createParams = {
    amount,
    currency,
    automatic_payment_methods: { enabled: true },
    metadata: {
      userId: String(userId),
      planId: planId || "",
      productId: PRODUCT_ID,
    },
  };
  if (stripeCustomerId) {
    createParams.customer = stripeCustomerId;
  }

  const createOptions = idempotencyKey && String(idempotencyKey).trim()
    ? { idempotencyKey: String(idempotencyKey).trim() }
    : {};

  try {
    const paymentIntent = await stripe.paymentIntents.create(createParams, createOptions);
    return {
      clientSecret: paymentIntent.client_secret,
      amount: paymentIntent.amount,
      currency: paymentIntent.currency || "usd",
    };
  } catch (err) {
    if (err.statusCode === 409) {
      const existingId = err.raw?.id || (err.raw?.paymentIntent && err.raw.paymentIntent.id);
      if (existingId) {
        const existing = await stripe.paymentIntents.retrieve(existingId);
        return {
          clientSecret: existing.client_secret,
          amount: existing.amount,
          currency: existing.currency || "usd",
        };
      }
    }
    const idempotencyKeyReuseMsg =
      err.message && String(err.message).toLowerCase().includes("idempotent");
    if (
      (err.statusCode === 400 || err.statusCode === 409) &&
      idempotencyKeyReuseMsg
    ) {
      const retry = await stripe.paymentIntents.create(createParams);
      return {
        clientSecret: retry.client_secret,
        amount: retry.amount,
        currency: retry.currency || "usd",
      };
    }
    throw err;
  }
}

/**
 * Create Stripe Checkout Session (redirect flow). successUrl and cancelUrl must be full URLs.
 */
async function createStripeCheckoutSession({ successUrl, cancelUrl, userId, userEmail, planId }) {
  const Stripe = require("stripe");
  const stripe = new Stripe(config.stripeSecretKey);
  const session = await stripe.checkout.sessions.create({
    payment_method_types: ["card"],
    line_items: getStripeLineItems(planId),
    mode: "payment",
    success_url: successUrl,
    cancel_url: cancelUrl,
    client_reference_id: String(userId),
    customer_email: userEmail,
    metadata: { userId: String(userId), product: PRODUCT_ID, planId: planId || "" },
  });
  return session;
}

/**
 * Get a single frontend base URL for redirects.
 * FRONTEND_URL can be comma-separated (e.g. http://localhost:3000,https://autocontentai.co).
 * Prefer https for production fallback.
 */
function getFrontendBaseUrl() {
  const raw = (config.frontendUrl || "").trim();
  if (!raw) return "http://localhost:3000";
  const urls = raw.split(",").map((u) => u.trim()).filter(Boolean);
  const https = urls.find((u) => u.startsWith("https://"));
  return https || urls[0] || "http://localhost:3000";
}

/**
 * Normalize success/cancel URLs from request body or use defaults.
 * Frontend usually sends full URLs (successUrl/cancelUrl). If not, we use FRONTEND_URL.
 * Note: Stripe Dashboard mein koi "frontend URL" setting nahi – yeh URL hum yahin se bhejte hain.
 */
function getCheckoutUrls(body) {
  const base = getFrontendBaseUrl();
  return {
    successUrl:
      (body?.successUrl && String(body.successUrl).trim()) ||
      `${base.replace(/\/$/, "")}/dashboard?checkout=success`,
    cancelUrl:
      (body?.cancelUrl && String(body.cancelUrl).trim()) ||
      `${base.replace(/\/$/, "")}/pricing`,
  };
}

// --- Create PaymentIntent for embedded checkout (auth required). Uses Stripe Price IDs per plan. ---
router.post("/create-payment-intent", auth, async (req, res) => {
  try {
    if (!config.stripeSecretKey) {
      return res.status(503).json({
        message: "Payment is not configured. Please set STRIPE_SECRET_KEY.",
      });
    }
    const rawPlanId = req.body?.planId != null ? String(req.body.planId).trim().toLowerCase() : null;
    const planId = rawPlanId && PLAN_LICENSES[rawPlanId] ? rawPlanId : null;
    if (!planId) {
      return res.status(400).json({ message: "Valid planId (single, plus, expert) is required." });
    }
    const userId = req.user.id;
    const userEmail = req.user.email || null;
    const idempotencyKey = req.body?.idempotencyKey != null ? String(req.body.idempotencyKey).trim() : null;
    const result = await createPaymentIntentForPlan(userId, planId, idempotencyKey || undefined, userEmail);
    return res.status(201).json({
      success: true,
      clientSecret: result.clientSecret,
      amount: result.amount,
      currency: result.currency,
    });
  } catch (err) {
    if (err.code === "INVALID_PLAN") {
      return res.status(400).json({ message: err.message });
    }
    return res.status(500).json({ message: err.message || "Failed to create payment intent" });
  }
});

// --- Checkout (redirect to Stripe) – optional fallback. Frontend can use embedded flow instead. ---
router.post("/checkout", auth, async (req, res) => {
  try {
    if (!config.stripeSecretKey) {
      return res.status(503).json({
        message: "Payment is not configured. Please set STRIPE_SECRET_KEY.",
      });
    }
    const { successUrl, cancelUrl } = getCheckoutUrls(req.body);
    const rawPlanId = req.body?.planId != null ? String(req.body.planId).trim().toLowerCase() : null;
    const planId = rawPlanId && PLAN_LICENSES[rawPlanId] ? rawPlanId : null;
    const userId = req.user.id;
    const userEmail = req.user.email;

    const session = await createStripeCheckoutSession({
      successUrl,
      cancelUrl,
      userId,
      userEmail,
      planId,
    });
    return res.status(201).json({
      success: true,
      url: session.url,
      sessionId: session.id,
    });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Checkout failed" });
  }
});

/**
 * Stripe webhook handler. Must be mounted with express.raw({ type: "application/json" }).
 * Handles: payment_intent.succeeded (embedded checkout), checkout.session.completed (redirect).
 * In Stripe Dashboard → Webhooks → Add event "payment_intent.succeeded" to your endpoint.
 */
async function stripeWebhookHandler(req, res) {
  const sig = req.headers["stripe-signature"];
  const rawBody = req.body;

  if (!config.stripeWebhookSecret || !config.stripeSecretKey) {
    return res.status(200).send();
  }

  let event;
  try {
    const Stripe = require("stripe");
    const stripe = new Stripe(config.stripeSecretKey);
    event = stripe.webhooks.constructEvent(rawBody, sig, config.stripeWebhookSecret);
  } catch (err) {
    return res.status(400).send(`Webhook signature verification failed: ${err.message}`);
  }

  if (event.type === "payment_intent.succeeded") {
    const paymentIntent = event.data.object;
    const paymentIntentId = paymentIntent.id;
    try {
      const existing = await Purchase.findOne({ stripePaymentIntentId: paymentIntentId });
      if (existing) {
        return res.status(200).send();
      }
      const userIdFromMeta = paymentIntent.metadata?.userId;
      if (!userIdFromMeta) {
        console.warn("[webhook] payment_intent.succeeded missing metadata.userId, pi:", paymentIntentId);
        return res.status(200).send();
      }
      const user = await User.findById(userIdFromMeta);
      if (!user) {
        console.warn("[webhook] payment_intent.succeeded user not found, userId:", userIdFromMeta);
        return res.status(200).send();
      }
      const amount = paymentIntent.amount_received ? paymentIntent.amount_received / 100 : DEFAULT_AMOUNT;
      const planId = paymentIntent.metadata?.planId && PLAN_LICENSES[paymentIntent.metadata.planId]
        ? paymentIntent.metadata.planId
        : null;
      const licenseCount = planId ? PLAN_LICENSES[planId] : 1;
      const assigned = await assignLicensesFromPool(user._id, licenseCount);
      await Purchase.create({
        userId: user._id,
        productId: PRODUCT_ID,
        amount,
        status: "completed",
        description: `WordPress Plugin – ${planId || "One-time"} (${assigned.length} license(s))`,
        invoiceUrl: null,
        stripeSessionId: null,
        stripePaymentIntentId: paymentIntentId,
      });
      if (assigned.length === 0) {
        console.error("[webhook] No license available in pool for user", user._id.toString());
      } else {
        console.log("[webhook] payment_intent.succeeded processed, pi:", paymentIntentId, "userId:", user._id.toString());
      }
      return res.status(200).send();
    } catch (err) {
      console.error("[webhook] Error processing payment_intent.succeeded:", err.message);
      return res.status(500).send();
    }
  }

  if (event.type === "checkout.session.completed") {
    const session = event.data.object;
    const sessionId = session.id;
    try {
      const existing = await Purchase.findOne({ stripeSessionId: sessionId });
      if (existing) {
        return res.status(200).send();
      }

      const email = session.customer_email || session.customer_details?.email;
      const userIdFromRef = session.client_reference_id || session.metadata?.userId;

      let user;
      if (userIdFromRef) {
        user = await User.findById(userIdFromRef);
      }
      if (!user && email) {
        user = await findOrCreateUserByEmail(email);
      }
      if (!user) {
        console.warn("[webhook] checkout.session.completed no user, sessionId:", sessionId);
        return res.status(200).send();
      }

      const amount = session.amount_total ? session.amount_total / 100 : DEFAULT_AMOUNT;
      const planId = session.metadata?.planId && PLAN_LICENSES[session.metadata.planId]
        ? session.metadata.planId
        : null;
      const licenseCount = planId ? PLAN_LICENSES[planId] : 1;
      const unusedCount = await License.countDocuments({ status: "unused" });
      console.log("[webhook] checkout.session.completed sessionId:", sessionId, "userId:", user._id.toString(), "unused:", unusedCount, "need:", licenseCount);
      const assigned = await assignLicensesFromPool(user._id, licenseCount);
      await Purchase.create({
        userId: user._id,
        productId: PRODUCT_ID,
        amount,
        status: "completed",
        description: `WordPress Plugin – ${planId || "One-time"} (${assigned.length} license(s))`,
        invoiceUrl: session.invoice || null,
        stripeSessionId: sessionId,
      });
      if (assigned.length === 0) {
        console.error("[webhook] No license available in pool for user", user._id.toString());
      }
      return res.status(200).send();
    } catch (err) {
      console.error("[webhook] Error processing checkout.session.completed:", err.message);
      return res.status(500).send();
    }
  }

  res.status(200).send();
}

router.get("/history", auth, async (req, res) => {
  try {
    const list = await Purchase.find({ userId: req.user.id })
      .sort({ createdAt: -1 })
      .lean();
    const out = list.map((p) => ({
      id: p._id.toString(),
      date: p.createdAt,
      amount: p.amount,
      status: p.status,
      description: p.description || "Purchase",
      invoiceUrl: p.invoiceUrl,
    }));
    return res.json(out);
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to load history" });
  }
});

/** GET /api/payments/debug-db – verify which DB backend is using (no auth). Remove in production if needed. */
router.get("/debug-db", async (req, res) => {
  try {
    const dbName = mongoose.connection?.db?.databaseName ?? "not connected";
    const unusedCount = await License.countDocuments({ status: "unused" });
    return res.json({ dbName, unusedLicenses: unusedCount });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
});

module.exports = { paymentRoutes: router, stripeWebhookHandler };
