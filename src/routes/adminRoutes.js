const express = require("express");
const { auth, adminOnly } = require("../middleware/auth");
const User = require("../models/User");
const License = require("../models/License");
const Purchase = require("../models/Purchase");

/** Assign one unused license from pool to user. Returns license or null. */
async function assignLicenseFromPool(userId, product = "wordpress-plugin") {
  const license = await License.findOneAndUpdate(
    { status: "unused" },
    {
      $set: {
        userId,
        status: "active",
        product,
        assignedAt: new Date(),
      },
    },
    { new: true }
  );
  return license;
}

const router = express.Router();

router.use(auth, adminOnly);

router.get("/users", async (req, res) => {
  try {
    const list = await User.find({}).sort({ createdAt: 1 }).lean();
    const out = list.map((u) => ({
      id: u._id.toString(),
      email: u.email,
      role: u.role || (u.isAdmin ? "admin" : "customer"),
      created_at: u.createdAt,
    }));
    return res.json(out);
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to load users" });
  }
});

router.get("/licenses", async (req, res) => {
  try {
    const list = await License.find({}).sort({ assignedAt: -1, createdAt: -1 }).lean();
    const out = list.map((l) => ({
      id: l._id.toString(),
      key: l.key,
      userId: l.userId?.toString?.() ?? l.userId,
      product: l.product,
      status: l.status || "unused",
      domain: l.domain || null,
      assignedAt: l.assignedAt,
      createdAt: l.createdAt,
    }));
    return res.json(out);
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to load licenses" });
  }
});

router.get("/purchases", async (req, res) => {
  try {
    const list = await Purchase.find({}).sort({ createdAt: -1 }).lean();
    const out = list.map((p) => ({
      id: p._id.toString(),
      user_id: p.userId?.toString?.() ?? p.userId,
      product: p.productId,
      amount: p.amount,
      payment_status: p.status,
      created_at: p.createdAt,
    }));
    return res.json(out);
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to load purchases" });
  }
});

router.post("/licenses/:id/revoke", async (req, res) => {
  try {
    const id = req.params.id;
    const license = await License.findByIdAndUpdate(
      id,
      { status: "revoked" },
      { new: true }
    );
    if (!license) {
      return res.status(404).json({ message: "License not found" });
    }
    return res.json({
      message: "License revoked",
      license: { id: license._id.toString(), status: license.status },
    });
  } catch (err) {
    if (err.name === "CastError") return res.status(404).json({ message: "License not found" });
    return res.status(500).json({ message: err.message || "Failed to revoke" });
  }
});

router.post("/licenses", async (req, res) => {
  try {
    const { userId: userIdParam, email, product = "wordpress-plugin" } = req.body || {};
    let user;
    if (userIdParam) {
      user = await User.findById(userIdParam);
    } else if (email) {
      user = await User.findOne({ email: String(email).trim().toLowerCase() });
    }
    if (!user) {
      return res.status(404).json({ message: "User not found for userId or email" });
    }
    const license = await assignLicenseFromPool(user._id, product);
    if (!license) {
      return res.status(503).json({ message: "No unused licenses in pool. Add more or contact support." });
    }
    const doc = license.toJSON();
    return res.status(201).json({
      id: doc.id,
      userId: doc.userId,
      key: doc.key,
      product: doc.product,
      status: doc.status,
      domain: doc.domain,
      assignedAt: doc.assignedAt,
      createdAt: doc.createdAt,
    });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Failed to issue license" });
  }
});

module.exports = { adminRoutes: router };
