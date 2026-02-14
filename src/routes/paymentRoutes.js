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
 * Create Stripe Checkout Session. successUrl and cancelUrl must be full URLs.
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
 * Normalize success/cancel URLs from request body or use defaults.
 * Frontend may send full URLs (e.g. https://site.com/dashboard?checkout=success).
 */
function getCheckoutUrls(body) {
  const base = config.frontendUrl || "";
  return {
    successUrl:
      (body?.successUrl && String(body.successUrl).trim()) ||
      `${base}/dashboard?checkout=success`,
    cancelUrl:
      (body?.cancelUrl && String(body.cancelUrl).trim()) || `${base}/pricing`,
  };
}

// --- Checkout (auth required). Stripe only; no mock. Frontend sends successUrl, cancelUrl, and optional planId (single|plus|expert). ---
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
 * Idempotent: skips if a Purchase already exists for this session ID.
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

  if (event.type === "checkout.session.completed") {
    try {
      const session = event.data.object;
      const sessionId = session.id;

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
        return res.status(200).send();
      }

      const amount = session.amount_total ? session.amount_total / 100 : DEFAULT_AMOUNT;
      const planId = session.metadata?.planId && PLAN_LICENSES[session.metadata.planId]
        ? session.metadata.planId
        : null;
      const licenseCount = planId ? PLAN_LICENSES[planId] : 1;
      const dbName = mongoose.connection?.db?.databaseName ?? "?";
      const unusedCount = await License.countDocuments({ status: "unused" });
      console.log("[webhook] DB:", dbName, "| Unused:", unusedCount, "| need:", licenseCount, "| userId:", user._id.toString());
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
        console.error("[webhook] No license available in pool for user", user._id);
      }
    } catch (err) {
      console.error("[webhook] Error processing checkout.session.completed:", err.message);
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
