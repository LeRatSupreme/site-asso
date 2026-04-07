import NextAuth from 'next-auth'
import { PrismaAdapter } from '@auth/prisma-adapter'
import Credentials from 'next-auth/providers/credentials'
import { compare } from 'bcryptjs'
import { prisma } from './prisma'
import { authConfig } from './auth.config'
import {
  clearLoginAttemptCounter,
  getLoginBlockRemainingSeconds,
  registerFailedLoginAttempt,
} from './rate-limit'

function getClientIp(request?: Request): string {
  if (!request) {
    return 'unknown'
  }

  const forwardedFor = request.headers.get('x-forwarded-for')
  if (forwardedFor) {
    return forwardedFor.split(',')[0]?.trim() || 'unknown'
  }

  return request.headers.get('x-real-ip')?.trim() || 'unknown'
}

function getRateLimitErrorMessage(retryAfterSeconds: number): string {
  return `Trop de tentatives de connexion. Réessayez dans ${retryAfterSeconds} secondes.`
}

export const { handlers, signIn, signOut, auth } = NextAuth({
  ...authConfig,
  adapter: PrismaAdapter(prisma),
  providers: [
    Credentials({
      name: 'credentials',
      credentials: {
        email: { label: 'Email', type: 'email' },
        password: { label: 'Mot de passe', type: 'password' },
      },
      async authorize(credentials, request) {
        if (!credentials?.email || !credentials?.password) {
          throw new Error('Email et mot de passe requis')
        }

        const email = String(credentials.email).trim()
        const password = String(credentials.password)
        const rateLimitKey = `${email.toLowerCase()}:${getClientIp(request)}`

        const remainingBlockSeconds = getLoginBlockRemainingSeconds(rateLimitKey)
        if (remainingBlockSeconds > 0) {
          throw new Error(getRateLimitErrorMessage(remainingBlockSeconds))
        }

        const user = await prisma.user.findUnique({
          where: { email },
        })

        if (!user || !user.password) {
          const blockedSeconds = registerFailedLoginAttempt(rateLimitKey)
          if (blockedSeconds > 0) {
            throw new Error(getRateLimitErrorMessage(blockedSeconds))
          }
          throw new Error('Identifiants invalides')
        }

        if (!user.isActive) {
          registerFailedLoginAttempt(rateLimitKey)
          throw new Error('Compte désactivé')
        }

        const isValid = await compare(password, user.password)

        if (!isValid) {
          const blockedSeconds = registerFailedLoginAttempt(rateLimitKey)
          if (blockedSeconds > 0) {
            throw new Error(getRateLimitErrorMessage(blockedSeconds))
          }
          throw new Error('Identifiants invalides')
        }

        clearLoginAttemptCounter(rateLimitKey)

        return {
          id: user.id,
          email: user.email,
          name: user.name,
          image: user.image,
          role: user.role,
          isActive: user.isActive,
        }
      },
    }),
  ],
})
