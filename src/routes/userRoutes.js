const express = require("express");
const { auth } = require("../middleware/auth");

const router = express.Router();

router.get("/me", auth, (req, res) => {
  return res.json(req.user);
});

module.exports = { userRoutes: router };
