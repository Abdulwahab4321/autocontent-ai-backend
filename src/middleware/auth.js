const jwt = require("jsonwebtoken");
const config = require("../config");
const User = require("../models/User");

async function auth(req, res, next) {
  const authHeader = req.headers.authorization;
  const token = authHeader?.startsWith("Bearer ") ? authHeader.slice(7) : null;
  if (!token) {
    return res.status(401).json({ message: "Authentication required" });
  }
  try {
    const decoded = jwt.verify(token, config.jwtSecret);
    const user = await User.findById(decoded.userId).lean();
    if (!user) {
      return res.status(401).json({ message: "User not found" });
    }
    req.user = {
      id: user._id.toString(),
      email: user.email,
      isAdmin: user.isAdmin || user.role === "admin",
      role: user.role || (user.isAdmin ? "admin" : "customer"),
    };
    next();
  } catch {
    return res.status(401).json({ message: "Invalid or expired token" });
  }
}

function adminOnly(req, res, next) {
  if (!req.user?.isAdmin) {
    return res.status(403).json({ message: "Admin access required" });
  }
  next();
}

module.exports = { auth, adminOnly };
