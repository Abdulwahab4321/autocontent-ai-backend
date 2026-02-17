const express = require("express");
const { auth } = require("../middleware/auth");
const License = require("../models/License");

const router = express.Router();

/** Normalize domain for comparison: trim, lowercase. */
function normalizeDomain(value) {
  if (value == null || typeof value !== "string") return null;
  const d = value.trim().toLowerCase();
  return d.length > 0 ? d : null;
}

function toLicenseResponse(license) {
  const doc = license.toJSON ? license.toJSON() : license;
  const domain = doc.domain != null && doc.domain !== ""
    ? doc.domain
    : (Array.isArray(doc.domains) && doc.domains[0]) || null;
  return {
    id: doc.id || doc._id?.toString(),
    userId: doc.userId?.toString?.() ?? doc.userId,
    key: doc.key,
    product: doc.product,
    status: doc.status || "active",
    domain,
    assignedAt: doc.assignedAt,
    createdAt: doc.createdAt,
  };
}

/** GET /api/licenses – list licenses assigned to the current user. */
router.get("/", auth, async (req, res) => {
  try {
    const list = await License.find({ userId: req.user.id }).sort({ assignedAt: -1 });
    const out = list.map(toLicenseResponse);
    return res.json(out.length ? { licenses: out } : []);
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to load licenses" });
  }
});

/**
 * POST /api/licenses/verify – plugin activation / re-verification.
 * Body: { key, domain }. Domain is required for activation; for re-verify plugin sends same key+domain.
 * If license is active and (not bound or bound to this domain): valid, and bind domain if not yet set.
 * If license is bound to another domain: invalid.
 */
router.post("/verify", async (req, res) => {
  try {
    const { key, domain } = req.body || {};
    const keyStr = key != null && typeof key === "string" ? key.trim() : "";
    if (!keyStr) {
      return res.status(400).json({ valid: false, message: "License key is required" });
    }
    const requestedDomain = normalizeDomain(domain);

    const license = await License.findOne({ key: keyStr });
    if (!license) {
      return res.status(404).json({ valid: false, message: "Invalid or inactive license key" });
    }
    if (license.status === "revoked") {
      return res.status(403).json({ valid: false, message: "License has been revoked" });
    }
    if (license.status === "unused") {
      return res.status(403).json({ valid: false, message: "License is not assigned" });
    }

    if (license.status !== "active") {
      return res.status(403).json({ valid: false, message: "Invalid or inactive license key" });
    }

    const currentDomain = license.domain ? normalizeDomain(license.domain) : null;
    if (requestedDomain == null || requestedDomain === "") {
      return res.status(400).json({ valid: false, message: "Domain is required for verification" });
    }

    if (currentDomain === null) {
      license.domain = requestedDomain;
      await license.save();
      return res.json({
        valid: true,
        status: "active",
        product: license.product || "wordpress-plugin",
      });
    }
    if (currentDomain === requestedDomain) {
      return res.json({
        valid: true,
        status: "active",
        product: license.product || "wordpress-plugin",
      });
    }
    return res.status(403).json({
      valid: false,
      message: "License is already connected to another website. Disconnect it from your dashboard to use it here.",
    });
  } catch (err) {
    return res.status(500).json({ valid: false, message: err.message || "Verification failed" });
  }
});

/**
 * POST /api/licenses/check – plugin check only (no bind, no state change).
 * Body: { key, domain }. Returns { valid: true } only if license is active and bound to this domain.
 * If license.domain is null (disconnected from dashboard), returns valid: false so plugin can clear local state.
 */
router.post("/check", async (req, res) => {
  try {
    const { key, domain } = req.body || {};
    const keyStr = key != null && typeof key === "string" ? key.trim() : "";
    if (!keyStr) {
      return res.status(400).json({ valid: false, message: "License key is required" });
    }
    const requestedDomain = normalizeDomain(domain);
    if (requestedDomain == null || requestedDomain === "") {
      return res.status(400).json({ valid: false, message: "Domain is required" });
    }

    const license = await License.findOne({ key: keyStr });
    if (!license) {
      return res.status(404).json({ valid: false, message: "Invalid or inactive license key" });
    }
    if (license.status === "revoked") {
      return res.json({ valid: false, message: "License has been revoked" });
    }
    if (license.status !== "active") {
      return res.json({ valid: false, message: "License is not active" });
    }

    const currentDomain = license.domain ? normalizeDomain(license.domain) : null;
    if (currentDomain === null) {
      return res.json({ valid: false, message: "License is not active on this domain." });
    }
    if (currentDomain !== requestedDomain) {
      return res.json({ valid: false, message: "License is active on another website." });
    }
    return res.json({
      valid: true,
      product: license.product || "wordpress-plugin",
    });
  } catch (err) {
    return res.status(500).json({ valid: false, message: err.message || "Check failed" });
  }
});

/** POST /api/licenses/:id/disconnect – user disconnects license from current domain so it can be used on another site. */
router.post("/:id/disconnect", auth, async (req, res) => {
  try {
    const id = req.params.id;
    const license = await License.findOne({ _id: id, userId: req.user.id });
    if (!license) {
      return res.status(404).json({ message: "License not found" });
    }
    if (license.domain == null || license.domain === "") {
      return res.status(400).json({ message: "License is already disconnected" });
    }
    license.domain = null;
    await license.save();
    return res.json({
      message: "License disconnected. You can now activate it on another website.",
      domain: null,
    });
  } catch (err) {
    if (err.name === "CastError") {
      return res.status(404).json({ message: "License not found" });
    }
    return res.status(500).json({ message: err.message || "Failed to disconnect" });
  }
});

module.exports = { licenseRoutes: router };
