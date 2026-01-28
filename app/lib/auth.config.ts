import NextAuth from 'next-auth'
import type { NextAuthConfig } from 'next-auth'

// Types pour la session
declare module 'next-auth' {
  interface Session {
    user: {
      id: string
      email: string
      name: string | null
      image: string | null
      role: 'ADMIN' | 'MEMBER' | 'STUDENT'
      isActive: boolean
    }
  }
  interface User {
    role: 'ADMIN' | 'MEMBER' | 'STUDENT'
    isActive: boolean
  }
}

declare module '@auth/core/jwt' {
  interface JWT {
    id: string
    role: 'ADMIN' | 'MEMBER' | 'STUDENT'
    isActive: boolean
  }
}

// Configuration minimale pour le middleware (Edge Runtime compatible)
// Ne pas utiliser PrismaAdapter ici car Prisma n'est pas compatible avec Edge Runtime
export const authConfig: NextAuthConfig = {
  session: { strategy: 'jwt' },
  pages: {
    signIn: '/login',
    error: '/login',
  },
  providers: [], // Les providers sont définis dans auth.ts
  callbacks: {
    authorized({ auth }) {
      // Cette fonction n'est pas utilisée car on gère l'autorisation dans le middleware
      return true
    },
    jwt({ token, user }) {
      if (user) {
        token.id = user.id!
        token.role = user.role
        token.isActive = user.isActive
      }
      return token
    },
    session({ session, token }) {
      if (token && session.user) {
        session.user.id = token.id as string
        session.user.role = token.role as 'ADMIN' | 'MEMBER' | 'STUDENT'
        session.user.isActive = token.isActive as boolean
      }
      return session
    },
  },
}

export const { auth } = NextAuth(authConfig)
