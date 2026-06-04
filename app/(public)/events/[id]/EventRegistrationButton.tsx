'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { CheckCircle, ChevronRight, Loader2, AlertCircle } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/app/components/ui/dialog'
import { Badge } from '@/app/components/ui/badge'
import { toast } from '@/app/components/ui/use-toast'
import { registerToEvent, unregisterFromEvent } from '@/app/actions/registrations.actions'
import { cn } from '@/app/lib/utils'

type Choice = { id: string; label: string }
type Variant = { id: string; label: string; required: boolean; choices: Choice[] }

interface EventRegistrationButtonProps {
  eventId: string
  isRegistered: boolean
  variants: Variant[]
}

export function EventRegistrationButton({
  eventId,
  isRegistered: initialIsRegistered,
  variants,
}: EventRegistrationButtonProps) {
  const router = useRouter()
  const [isRegistered, setIsRegistered] = useState(initialIsRegistered)
  const [isLoading, setIsLoading] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  // Map variantId → choiceId
  const [selections, setSelections] = useState<Record<string, string>>({})

  const hasVariants = variants.length > 0
  const requiredVariants = variants.filter((v) => v.required)

  const allRequiredSelected = requiredVariants.every((v) => !!selections[v.id])

  function select(variantId: string, choiceId: string) {
    setSelections((prev) => ({ ...prev, [variantId]: choiceId }))
  }

  async function handleRegister() {
    setIsLoading(true)
    try {
      const choices = Object.entries(selections).map(([variantId, choiceId]) => ({
        variantId,
        choiceId,
      }))
      const result = await registerToEvent(eventId, choices)
      if (result.success) {
        setIsRegistered(true)
        setDialogOpen(false)
        toast({ title: 'Inscription confirmée !', variant: 'success' })
        router.refresh()
      } else {
        toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
      }
    } catch {
      toast({ title: 'Erreur', description: 'Une erreur est survenue', variant: 'destructive' })
    } finally {
      setIsLoading(false)
    }
  }

  async function handleUnregister() {
    setIsLoading(true)
    try {
      const result = await unregisterFromEvent(eventId)
      if (result.success) {
        setIsRegistered(false)
        setSelections({})
        toast({ title: 'Désinscription effectuée' })
        router.refresh()
      } else {
        toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
      }
    } catch {
      toast({ title: 'Erreur', description: 'Une erreur est survenue', variant: 'destructive' })
    } finally {
      setIsLoading(false)
    }
  }

  // ─── Already registered ───────────────────────────────────────────────────
  if (isRegistered) {
    return (
      <Button
        onClick={handleUnregister}
        disabled={isLoading}
        variant="secondary"
        className="w-full h-11 gap-2"
      >
        {isLoading ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <CheckCircle className="h-4 w-4 text-emerald-500" />
        )}
        Inscrit — cliquer pour annuler
      </Button>
    )
  }

  // ─── No variants → direct registration ───────────────────────────────────
  if (!hasVariants) {
    return (
      <Button
        onClick={handleRegister}
        disabled={isLoading}
        className="w-full h-11 gap-2"
      >
        {isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
        S&apos;inscrire
      </Button>
    )
  }

  // ─── With variants → open dialog first ───────────────────────────────────
  return (
    <>
      <Button
        onClick={() => setDialogOpen(true)}
        className="w-full h-11 gap-2"
      >
        S&apos;inscrire
        <ChevronRight className="h-4 w-4" />
      </Button>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="text-xl font-black">Choisissez vos options</DialogTitle>
            <DialogDescription>
              Sélectionnez une option pour chaque variante avant de confirmer votre inscription.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5 py-2">
            {variants.map((variant) => (
              <div key={variant.id} className="space-y-2.5">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-semibold">{variant.label}</span>
                  {variant.required ? (
                    <Badge variant="default" className="text-[10px] py-0">Obligatoire</Badge>
                  ) : (
                    <Badge variant="secondary" className="text-[10px] py-0">Optionnel</Badge>
                  )}
                </div>

                {variant.choices.length === 0 ? (
                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <AlertCircle className="h-3.5 w-3.5" />
                    Aucun choix disponible pour cette variante
                  </div>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {variant.choices.map((choice) => {
                      const selected = selections[variant.id] === choice.id
                      return (
                        <button
                          key={choice.id}
                          type="button"
                          onClick={() => select(variant.id, choice.id)}
                          className={cn(
                            'px-4 py-2 rounded-xl border text-sm font-medium transition-all duration-150',
                            selected
                              ? 'bg-primary text-primary-foreground border-primary shadow-md'
                              : 'bg-muted/40 border-border/60 hover:border-primary/50 hover:bg-primary/5 text-foreground'
                          )}
                        >
                          {selected && <CheckCircle className="inline h-3.5 w-3.5 mr-1.5 -mt-0.5" />}
                          {choice.label}
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>
            ))}

            {/* Summary of selections */}
            {Object.keys(selections).length > 0 && (
              <div className="rounded-xl bg-muted/40 border border-border/50 p-3 space-y-1">
                <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2">Récapitulatif</p>
                {variants.map((v) => {
                  const choiceId = selections[v.id]
                  const choiceLabel = v.choices.find((c) => c.id === choiceId)?.label
                  if (!choiceLabel) return null
                  return (
                    <div key={v.id} className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">{v.label}</span>
                      <span className="font-semibold text-primary">{choiceLabel}</span>
                    </div>
                  )
                })}
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={isLoading}>
              Annuler
            </Button>
            <Button
              onClick={handleRegister}
              disabled={isLoading || !allRequiredSelected}
              className="gap-2"
            >
              {isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
              Confirmer l&apos;inscription
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
