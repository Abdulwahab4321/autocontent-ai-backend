const express = require("express");
const jwt = require("jsonwebtoken");
const crypto = require("crypto");
const config = require("../config");
const User = require("../models/User");
const ResetToken = require("../models/ResetToken");

const router = express.Router();

function hashPassword(password) {
  return crypto.scryptSync(password, "autocontent-salt", 64).toString("hex");
}

function createToken(user) {
  return jwt.sign(
    { userId: user.id || user._id?.toString(), email: user.email },
    config.jwtSecret,
    { expiresIn: "7d" }
  );
}

function isAdminEmail(email) {
  const adminEmails = (process.env.ADMIN_EMAILS || "admin@example.com")
    .split(",")
    .map((e) => e.trim().toLowerCase());
  return adminEmails.includes(email);
}

router.post("/signup", async (req, res) => {
  try {
    const { email, password } = req.body || {};
    const trimmedEmail = typeof email === "string" ? email.trim().toLowerCase() : "";

    if (!trimmedEmail) {
      return res.status(400).json({ message: "Email is required" });
    }
    if (!password || typeof password !== "string") {
      return res.status(400).json({ message: "Password is required" });
    }
    if (password.length < 6) {
      return res.status(400).json({ message: "Password must be at least 6 characters" });
    }

    const existing = await User.findOne({ email: trimmedEmail });
    if (existing) {
      return res.status(409).json({ message: "An account with this email already exists" });
    }

    const role = isAdminEmail(trimmedEmail) ? "admin" : "customer";
    const user = await User.create({
      email: trimmedEmail,
      passwordHash: hashPassword(password),
      role,
      isAdmin: role === "admin",
    });

    const safeUser = { id: user._id.toString(), email: user.email };
    const token = createToken(safeUser);
    return res.status(201).json({ user: safeUser, token });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Signup failed" });
  }
});

router.post("/login", async (req, res) => {
  try {
    const { email, password } = req.body || {};
    const trimmedEmail = typeof email === "string" ? email.trim().toLowerCase() : "";

    if (!trimmedEmail || !password) {
      return res.status(400).json({ message: "Email and password are required" });
    }

    const user = await User.findOne({ email: trimmedEmail });
    if (!user) {
      return res.status(401).json({ message: "Invalid email or password" });
    }

    const hash = hashPassword(password);
    if (hash !== user.passwordHash) {
      return res.status(401).json({ message: "Invalid email or password" });
    }

    const safeUser = { id: user._id.toString(), email: user.email };
    const token = createToken(safeUser);
    return res.json({ user: safeUser, token });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Login failed" });
  }
});

function sendPasswordResetEmail(toEmail, resetUrl) {
  if (!process.env.SMTP_HOST) {
    console.warn("[forgot-password] SMTP_HOST not set in .env – no email will be sent.");
    return Promise.resolve();
  }
  const nodemailer = require("nodemailer");
  const transporter = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: Number(process.env.SMTP_PORT) || 587,
    secure: process.env.SMTP_SECURE === "true",
    auth: process.env.SMTP_USER
      ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS }
      : undefined,
  });
  return transporter.sendMail({
    from: process.env.SMTP_FROM || process.env.SMTP_USER || "noreply@example.com",
    to: toEmail,
    subject: "Reset your AutoContent AI password",
    text: `Use this link to reset your password (valid 1 hour): ${resetUrl}`,
    html: `<p>Use this link to reset your password (valid 1 hour):</p><p><a href="${resetUrl}">${resetUrl}</a></p>`,
  });
}

router.post("/forgot-password", async (req, res) => {
  try {
    const { email } = req.body || {};
    const trimmedEmail = typeof email === "string" ? email.trim().toLowerCase() : "";
    if (!trimmedEmail) {
      return res.status(400).json({ message: "Email is required" });
    }

    console.log("[forgot-password] Request for:", trimmedEmail);

    const user = await User.findOne({ email: trimmedEmail });
    if (!user) {
      console.log("[forgot-password] No user found for this email.");
      return res.json({ message: "If an account exists, you will receive a reset link." });
    }

    const token = crypto.randomBytes(32).toString("hex");
    const expiresAt = new Date(Date.now() + 60 * 60 * 1000);
    await ResetToken.create({ token, userId: user._id, expiresAt });
    const resetUrl = `${config.frontendUrl}/reset-password?token=${token}`;

    console.log("[forgot-password] Sending reset email to:", trimmedEmail);
    sendPasswordResetEmail(trimmedEmail, resetUrl)
      .then(() => console.log("[forgot-password] Email sent successfully to:", trimmedEmail))
      .catch((err) => console.error("[forgot-password] Email send failed:", err.message));

    return res.json({ message: "If an account exists, you will receive a reset link." });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Request failed" });
  }
});

router.post("/reset-password", async (req, res) => {
  try {
    const { token, newPassword } = req.body || {};
    if (!token || !newPassword || newPassword.length < 8) {
      return res.status(400).json({ message: "Token and new password (min 8 characters) are required" });
    }

    const data = await ResetToken.findOne({ token });
    if (!data || data.expiresAt < new Date()) {
      return res.status(400).json({ message: "Invalid or expired reset link" });
    }

    await User.findByIdAndUpdate(data.userId, {
      passwordHash: hashPassword(newPassword),
    });
    await ResetToken.deleteOne({ token });

    return res.json({ message: "Password has been reset. You can log in with your new password." });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Reset failed" });
  }
});

const { auth } = require("../middleware/auth");

router.post("/change-password", auth, async (req, res) => {
  try {
    const { currentPassword, newPassword } = req.body || {};
    if (!currentPassword || !newPassword) {
      return res.status(400).json({ message: "Current password and new password are required" });
    }
    if (newPassword.length < 8) {
      return res.status(400).json({ message: "New password must be at least 8 characters" });
    }

    const user = await User.findById(req.user.id);
    if (!user) {
      return res.status(401).json({ message: "User not found" });
    }
    const currentHash = hashPassword(currentPassword);
    if (currentHash !== user.passwordHash) {
      return res.status(401).json({ message: "Current password is incorrect" });
    }
    user.passwordHash = hashPassword(newPassword);
    await user.save();
    return res.json({ message: "Password updated successfully" });
  } catch (err) {
    return res.status(500).json({ message: err.message || "Update failed" });
  }
});

module.exports = { authRoutes: router };
