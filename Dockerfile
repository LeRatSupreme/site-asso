# Dockerfile pour Next.js 15
FROM node:20-alpine AS base

# Installation des dépendances nécessaires pour Prisma
RUN apk add --no-cache libc6-compat openssl

WORKDIR /app

# Copier les fichiers de dépendances
COPY package*.json ./
COPY prisma ./prisma/

# Installer les dépendances
RUN npm ci

# Générer le client Prisma
RUN npx prisma generate

# Copier tout le code source
COPY . .

# Build de l'application Next.js
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

# Stage de production
FROM node:20-alpine AS runner
WORKDIR /app

# Installation d'openssl pour Prisma
RUN apk add --no-cache openssl

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

# Créer un utilisateur non-root
RUN addgroup --system --gid 1001 nodejs
RUN adduser --system --uid 1001 nextjs

# Copier les fichiers nécessaires depuis le build
COPY --from=base /app/public ./public
COPY --from=base /app/.next/standalone ./
COPY --from=base /app/.next/static ./.next/static
COPY --from=base /app/prisma ./prisma
COPY --from=base /app/node_modules/.prisma ./node_modules/.prisma

# Créer le dossier uploads
RUN mkdir -p ./public/uploads && chown -R nextjs:nodejs ./public/uploads

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
