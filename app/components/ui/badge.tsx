import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/app/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold tracking-wide transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
  {
    variants: {
      variant: {
        default:
          'border-transparent bg-primary text-primary-foreground',
        secondary:
          'border-white/10 bg-white/[0.06] text-white/70',
        destructive:
          'border-transparent bg-red-600 text-white',
        outline: 
          'border-white/15 bg-transparent text-white/70',
        success:
          'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
        warning:
          'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        info:
          'border-primary/20 bg-primary/10 text-primary',
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
