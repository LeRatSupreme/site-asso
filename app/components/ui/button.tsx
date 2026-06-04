import * as React from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/app/lib/utils'

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[14px] text-[11px] font-extrabold uppercase tracking-[0.14em] ring-offset-background transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 disabled:cursor-not-allowed active:translate-y-px',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground shadow-[0_0_22px_rgba(72,189,211,.2)] hover:bg-white',
        destructive: 'bg-red-600 text-white shadow-sm hover:bg-red-700',
        outline: 'border border-white/15 bg-white/[0.04] text-white/70 hover:border-primary/50 hover:bg-primary/[0.06] hover:text-primary',
        secondary: 'bg-white/[0.07] text-white/75 hover:bg-white/[0.12]',
        ghost: 'text-white/60 hover:bg-white/[0.05] hover:text-primary',
        link: 'text-primary underline-offset-4 hover:underline',
        gradient: 'bg-primary text-primary-foreground hover:bg-white',
      },
      size: {
        default: 'h-10 px-5 py-2',
        sm: 'h-9 rounded-lg px-4 text-xs',
        lg: 'h-12 rounded-xl px-8 text-base',
        xl: 'h-14 rounded-2xl px-10 text-lg',
        icon: 'h-10 w-10 rounded-xl',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button'
    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        {...props}
      />
    )
  }
)
Button.displayName = 'Button'

export { Button, buttonVariants }
