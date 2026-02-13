const http = require("http");
const app = require("./src/app");
const { connectDB } = require("./src/db");

const PORT = process.env.PORT || 4000;
const server = http.createServer(app);

(async () => {
  await connectDB();
  server.listen(PORT, () => {
    console.log(`API running on http://localhost:${PORT}`);
  });
})().catch((err) => {
  console.error("Startup failed:", err);
  process.exit(1);
});
