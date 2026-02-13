# AutoContent AI Backend - Production image
FROM node:20-alpine

WORKDIR /app

# Install dependencies first (better layer caching)
COPY package*.json ./
RUN npm ci --only=production

# Copy app source
COPY . .

# App listens on PORT from env (DigitalOcean sets it)
EXPOSE 4000

USER node
CMD ["node", "server.js"]
