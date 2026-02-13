/**
 * PM2 config for Droplet. Run: pm2 start ecosystem.config.js
 * Backend will use PORT=4000 (existing app on 8000 remains untouched).
 */
module.exports = {
  apps: [
    {
      name: "autocontent-ai-api",
      script: "server.js",
      cwd: __dirname,
      instances: 1,
      exec_mode: "fork",
      env: { NODE_ENV: "production", PORT: 4000 },
      max_memory_restart: "400M",
    },
  ],
};
