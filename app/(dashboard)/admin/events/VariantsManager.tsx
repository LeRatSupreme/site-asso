'use client'

import { useState } from 'react'
import { Plus, Trash2, GripVertical, Tag, ToggleLeft, ToggleRight, Loader2, X, Check } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import { Badge } from '@/app/components/ui/badge'
import { Switch } from '@/app/components/ui/switch'
import { Label } from '@/app/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import { toast } from '@/app/components/ui/use-toast'
import { cn } from '@/app/lib/utils'
import {
  createVariant,
  updateVariant,
  deleteVariant,
  addChoice,
  deleteChoice,
} from '@/app/actions/variants.actions'

type Choice = { id: string; label: string; order: number }
type Variant = { id: string; label: string; required: boolean; order: number; choices: Choice[] }

interface VariantsManagerProps {
  eventId: string
  initialVariants: Variant[]
}

export function VariantsManager({ eventId, initialVariants }: VariantsManagerProps) {
  const [variants, setVariants] = useState<Variant[]>(initialVariants)
  const [loading, setLoading] = useState<string | null>(null)

  // ─── New variant form state ────────────────────────────────────────────────
  const [showNewVariant, setShowNewVariant] = useState(false)
  const [newLabel, setNewLabel] = useState('')
  const [newRequired, setNewRequired] = useState(true)

  // ─── New choice inputs per variant ────────────────────────────────────────
  const [choiceInputs, setChoiceInputs] = useState<Record<string, string>>({})

  const setChoiceInput = (variantId: string, value: string) =>
    setChoiceInputs((prev) => ({ ...prev, [variantId]: value }))

  // ─── Handlers ─────────────────────────────────────────────────────────────

  async function handleCreateVariant() {
    if (!newLabel.trim()) return
    setLoading('new-variant')
    const result = await createVariant(eventId, { label: newLabel.trim(), required: newRequired })
    setLoading(null)
    if (result.success && result.variant) {
      setVariants((prev) => [...prev, result.variant as Variant])
      setNewLabel('')
      setNewRequired(true)
      setShowNewVariant(false)
      toast({ title: 'Variante ajoutée', variant: 'success' })
    } else {
      toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
    }
  }

  async function handleToggleRequired(variant: Variant) {
    setLoading(`req-${variant.id}`)
    const result = await updateVariant(variant.id, {
      label: variant.label,
      required: !variant.required,
    })
    setLoading(null)
    if (result.success && result.variant) {
      setVariants((prev) =>
        prev.map((v) => (v.id === variant.id ? { ...v, required: !v.required } : v))
      )
    } else {
      toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
    }
  }

  async function handleDeleteVariant(variantId: string) {
    setLoading(`del-${variantId}`)
    const result = await deleteVariant(variantId)
    setLoading(null)
    if (result.success) {
      setVariants((prev) => prev.filter((v) => v.id !== variantId))
      toast({ title: 'Variante supprimée' })
    } else {
      toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
    }
  }

  async function handleAddChoice(variantId: string) {
    const label = (choiceInputs[variantId] || '').trim()
    if (!label) return
    setLoading(`choice-${variantId}`)
    const result = await addChoice(variantId, { label })
    setLoading(null)
    if (result.success && result.choice) {
      setVariants((prev) =>
        prev.map((v) =>
          v.id === variantId
            ? { ...v, choices: [...v.choices, result.choice as Choice] }
            : v
        )
      )
      setChoiceInput(variantId, '')
    } else {
      toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
    }
  }

  async function handleDeleteChoice(variantId: string, choiceId: string) {
    setLoading(`delc-${choiceId}`)
    const result = await deleteChoice(choiceId)
    setLoading(null)
    if (result.success) {
      setVariants((prev) =>
        prev.map((v) =>
          v.id === variantId ? { ...v, choices: v.choices.filter((c) => c.id !== choiceId) } : v
        )
      )
    } else {
      toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-4 pb-3">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <Tag className="h-4 w-4 text-primary" />
            Options / Variantes
          </CardTitle>
          <p className="text-xs text-muted-foreground mt-0.5">
            Les participants devront sélectionner une option par variante lors de leur inscription.
          </p>
        </div>
        {!showNewVariant && (
          <Button
            size="sm"
            variant="outline"
            className="flex-shrink-0 gap-1.5"
            onClick={() => setShowNewVariant(true)}
          >
            <Plus className="h-3.5 w-3.5" />
            Ajouter
          </Button>
        )}
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Form pour nouvelle variante */}
        {showNewVariant && (
          <div className="rounded-xl border border-primary/30 bg-primary/5 p-4 space-y-3">
            <p className="text-sm font-semibold">Nouvelle variante</p>
            <div className="flex gap-2">
              <Input
                placeholder="ex: Menu, Type de plat..."
                value={newLabel}
                onChange={(e) => setNewLabel(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleCreateVariant()}
                className="flex-1 h-9"
                autoFocus
              />
            </div>
            <div className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-2">
                <Switch
                  id="new-required"
                  checked={newRequired}
                  onCheckedChange={setNewRequired}
                />
                <Label htmlFor="new-required" className="text-xs cursor-pointer">
                  Obligatoire
                </Label>
              </div>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-8 text-xs"
                  onClick={() => { setShowNewVariant(false); setNewLabel('') }}
                  disabled={loading === 'new-variant'}
                >
                  Annuler
                </Button>
                <Button
                  size="sm"
                  className="h-8 text-xs gap-1"
                  onClick={handleCreateVariant}
                  disabled={!newLabel.trim() || loading === 'new-variant'}
                >
                  {loading === 'new-variant' ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Check className="h-3.5 w-3.5" />
                  )}
                  Créer
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Liste des variantes existantes */}
        {variants.length === 0 && !showNewVariant && (
          <div className="text-center py-8 text-sm text-muted-foreground">
            Aucune variante — cet événement ne demandera aucun choix à l&apos;inscription.
          </div>
        )}

        {variants.map((variant) => (
          <div
            key={variant.id}
            className="rounded-xl border border-border/60 bg-card overflow-hidden"
          >
            {/* Header variante */}
            <div className="flex items-center gap-3 px-4 py-3 bg-muted/30 border-b border-border/40">
              <GripVertical className="h-4 w-4 text-muted-foreground/40 flex-shrink-0" />
              <span className="font-semibold text-sm flex-1 min-w-0 truncate">{variant.label}</span>

              <Badge
                variant={variant.required ? 'default' : 'secondary'}
                className="text-[10px] flex-shrink-0"
              >
                {variant.required ? 'Obligatoire' : 'Optionnel'}
              </Badge>

              {/* Toggle required */}
              <button
                onClick={() => handleToggleRequired(variant)}
                disabled={loading === `req-${variant.id}`}
                title={variant.required ? 'Rendre optionnel' : 'Rendre obligatoire'}
                className="text-muted-foreground hover:text-foreground transition-colors flex-shrink-0"
              >
                {loading === `req-${variant.id}` ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : variant.required ? (
                  <ToggleRight className="h-5 w-5 text-primary" />
                ) : (
                  <ToggleLeft className="h-5 w-5" />
                )}
              </button>

              {/* Delete variant */}
              <button
                onClick={() => handleDeleteVariant(variant.id)}
                disabled={loading === `del-${variant.id}`}
                className="text-muted-foreground hover:text-destructive transition-colors flex-shrink-0"
                title="Supprimer la variante"
              >
                {loading === `del-${variant.id}` ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Trash2 className="h-4 w-4" />
                )}
              </button>
            </div>

            {/* Choices */}
            <div className="p-3 space-y-2">
              {variant.choices.length === 0 && (
                <p className="text-xs text-muted-foreground px-1">Aucun choix — ajoutez-en ci-dessous.</p>
              )}

              <div className="flex flex-wrap gap-2">
                {variant.choices.map((choice) => (
                  <span
                    key={choice.id}
                    className="inline-flex items-center gap-1.5 pl-3 pr-1.5 py-1 rounded-full bg-muted/60 border border-border/50 text-sm font-medium"
                  >
                    {choice.label}
                    <button
                      onClick={() => handleDeleteChoice(variant.id, choice.id)}
                      disabled={loading === `delc-${choice.id}`}
                      className={cn(
                        'w-4 h-4 rounded-full flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors',
                        loading === `delc-${choice.id}` && 'opacity-50 pointer-events-none'
                      )}
                    >
                      {loading === `delc-${choice.id}` ? (
                        <Loader2 className="h-2.5 w-2.5 animate-spin" />
                      ) : (
                        <X className="h-2.5 w-2.5" />
                      )}
                    </button>
                  </span>
                ))}
              </div>

              {/* Add choice input */}
              <div className="flex gap-2 pt-1">
                <Input
                  placeholder="Ajouter un choix (ex: Halal, Végé...)"
                  value={choiceInputs[variant.id] || ''}
                  onChange={(e) => setChoiceInput(variant.id, e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && handleAddChoice(variant.id)}
                  className="h-8 text-sm flex-1"
                  disabled={loading === `choice-${variant.id}`}
                />
                <Button
                  size="sm"
                  variant="outline"
                  className="h-8 px-3 gap-1 flex-shrink-0"
                  onClick={() => handleAddChoice(variant.id)}
                  disabled={!choiceInputs[variant.id]?.trim() || loading === `choice-${variant.id}`}
                >
                  {loading === `choice-${variant.id}` ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Plus className="h-3.5 w-3.5" />
                  )}
                  Ajouter
                </Button>
              </div>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
