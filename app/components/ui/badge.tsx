import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/app/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
  {
    variants: {
      variant: {
        default:
          'border-transparent bg-gradient-to-r from-blue-500 to-violet-500 text-white shadow-sm',
        secondary:
          'border-transparent bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
        destructive:
          'border-transparent bg-gradient-to-r from-red-500 to-red-600 text-white',
        outline: 
          'border-2 border-blue-500 text-blue-600 bg-transparent',
        success:
          'border-transparent bg-gradient-to-r from-emerald-500 to-green-500 text-white',
        warning:
          'border-transparent bg-gradient-to-r from-amber-400 to-orange-500 text-white',
        info:
          'border-transparent bg-gradient-to-r from-cyan-500 to-blue-500 text-white',
        ghost:
          'border-transparent bg-muted text-muted-foreground hover:bg-muted/80',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <div className={cn(badgeVariants({ variant }), className)} {...props} />
  )
}

export { Badge, badgeVariants }
