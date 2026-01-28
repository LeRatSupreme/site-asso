'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { signOut } from 'next-auth/react'
import { LogOut, User, Settings, LayoutDashboard, ChevronDown } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/app/components/ui/avatar'
import { Button } from '@/app/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/app/components/ui/dropdown-menu'
import { Badge } from '@/app/components/ui/badge'
import { getRoleLabel } from '@/app/lib/roles'
import type { Role } from '@prisma/client'

interface UserNavProps {
  user: {
    id: string
    name: string | null
    email: string
    image: string | null
    role: Role
  }
}

export function UserNav({ user }: UserNavProps) {
  const router = useRouter()

  const handleSignOut = async () => {
    await signOut({ redirect: false })
    router.push('/login')
    router.refresh()
  }

  const dashboardUrl = user.role === 'ADMIN' ? '/admin' : '/eleve'
  const initials = user.name
    ? user.name.split(' ').map((n) => n[0]).join('').toUpperCase()
    : user.email[0].toUpperCase()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button 
          variant="ghost" 
          className="relative flex items-center gap-2 h-10 px-2 rounded-xl hover:bg-blue-50 dark:hover:bg-blue-950/50 transition-colors"
        >
          <Avatar className="h-8 w-8 ring-2 ring-blue-500/20">
            <AvatarImage src={user.image || ''} alt={user.name || ''} />
            <AvatarFallback className="bg-gradient-to-br from-blue-500 to-violet-500 text-white text-sm font-medium">
              {initials}
            </AvatarFallback>
          </Avatar>
          <span className="hidden sm:inline-block text-sm font-medium max-w-[100px] truncate">
            {user.name?.split(' ')[0] || 'User'}
          </span>
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-64 p-2" align="end" forceMount>
        <DropdownMenuLabel className="font-normal p-3 bg-gradient-to-br from-blue-50 to-violet-50 dark:from-blue-950/50 dark:to-violet-950/50 rounded-xl mb-2">
          <div className="flex items-center gap-3">
            <Avatar className="h-12 w-12 ring-2 ring-white shadow-lg">
              <AvatarImage src={user.image || ''} alt={user.name || ''} />
              <AvatarFallback className="bg-gradient-to-br from-blue-500 to-violet-500 text-white font-medium">
                {initials}
              </AvatarFallback>
            </Avatar>
            <div className="flex flex-col space-y-1 flex-1 min-w-0">
              <p className="text-sm font-semibold leading-none truncate">{user.name}</p>
              <p className="text-xs leading-none text-muted-foreground truncate">
                {user.email}
              </p>
              <Badge 
                variant={user.role === 'ADMIN' ? 'default' : 'secondary'}
                className="w-fit mt-1 text-xs"
              >
                {getRoleLabel(user.role)}
              </Badge>
            </div>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuGroup>
          <DropdownMenuItem asChild className="rounded-lg cursor-pointer">
            <Link href={dashboardUrl} className="flex items-center gap-3 p-2">
              <div className="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/50">
                <LayoutDashboard className="h-4 w-4 text-blue-600 dark:text-blue-400" />
              </div>
              <span className="font-medium">Dashboard</span>
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild className="rounded-lg cursor-pointer">
            <Link href="/eleve/profile" className="flex items-center gap-3 p-2">
              <div className="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/50">
                <User className="h-4 w-4 text-violet-600 dark:text-violet-400" />
              </div>
              <span className="font-medium">Mon profil</span>
            </Link>
          </DropdownMenuItem>
          {user.role === 'ADMIN' && (
            <DropdownMenuItem asChild className="rounded-lg cursor-pointer">
              <Link href="/admin/settings" className="flex items-center gap-3 p-2">
                <div className="p-2 rounded-lg bg-gray-100 dark:bg-gray-800">
                  <Settings className="h-4 w-4 text-gray-600 dark:text-gray-400" />
                </div>
                <span className="font-medium">Paramètres</span>
              </Link>
            </DropdownMenuItem>
          )}
        </DropdownMenuGroup>
        <DropdownMenuSeparator className="my-2" />
        <DropdownMenuItem 
          onClick={handleSignOut}
          className="rounded-lg cursor-pointer text-red-600 dark:text-red-400 focus:text-red-600 focus:bg-red-50 dark:focus:bg-red-950/50"
        >
          <div className="flex items-center gap-3 p-2">
            <div className="p-2 rounded-lg bg-red-100 dark:bg-red-900/50">
              <LogOut className="h-4 w-4" />
            </div>
            <span className="font-medium">Déconnexion</span>
          </div>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
