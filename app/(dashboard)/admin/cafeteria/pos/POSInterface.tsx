'use client'

import { useState, useCallback } from 'react'
import Image from 'next/image'
import { 
  Plus, 
  Minus, 
  Trash2, 
  ShoppingCart, 
  CreditCard, 
  Banknote,
  X,
  Check,
  Package,
  Search,
  User,
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent } from '@/app/components/ui/card'
import { Input } from '@/app/components/ui/input'
import { Badge } from '@/app/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/app/components/ui/tabs'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/app/components/ui/dialog'
import { Textarea } from '@/app/components/ui/textarea'
import { cn } from '@/app/lib/utils'
import { createPOSOrder } from '@/app/actions/pos.actions'
import { toast } from '@/app/components/ui/use-toast'

interface Product {
  id: string
  name: string
  price: number
  costPrice: number | null
  image: string | null
  stock: number
  isAvailable: boolean
  isActive: boolean
  categoryId: string | null
}

interface Category {
  id: string
  name: string
  products: Product[]
}

interface CartItem {
  product: Product
  quantity: number
}

interface POSInterfaceProps {
  categories: Category[]
  uncategorizedProducts: Product[]
}

export function POSInterface({ categories, uncategorizedProducts }: POSInterfaceProps) {
  const [cart, setCart] = useState<CartItem[]>([])
  const [searchQuery, setSearchQuery] = useState('')
  const [activeCategory, setActiveCategory] = useState<string>('all')
  const [checkoutOpen, setCheckoutOpen] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<'CASH' | 'CARD' | 'SUMUP'>('CASH')
  const [notes, setNotes] = useState('')
  const [isProcessing, setIsProcessing] = useState(false)
  const [customerName, setCustomerName] = useState('')

  // Tous les produits disponibles
  const allProducts = [
    ...categories.flatMap(c => c.products),
    ...uncategorizedProducts
  ].filter(p => p.isActive && p.isAvailable)

  // Filtrage des produits
  const filteredProducts = allProducts.filter(product => {
    const matchesSearch = product.name.toLowerCase().includes(searchQuery.toLowerCase())
    const matchesCategory = activeCategory === 'all' || product.categoryId === activeCategory
    return matchesSearch && matchesCategory
  })

  // Ajouter au panier
  const addToCart = useCallback((product: Product) => {
    setCart(prev => {
      const existing = prev.find(item => item.product.id === product.id)
      if (existing) {
        // Vérifier le stock
        if (existing.quantity >= product.stock) {
          toast({ title: 'Stock insuffisant', variant: 'destructive' })
          return prev
        }
        return prev.map(item =>
          item.product.id === product.id
            ? { ...item, quantity: item.quantity + 1 }
            : item
        )
      }
      if (product.stock < 1) {
        toast({ title: 'Produit en rupture de stock', variant: 'destructive' })
        return prev
      }
      return [...prev, { product, quantity: 1 }]
    })
  }, [])

  // Modifier la quantité
  const updateQuantity = useCallback((productId: string, delta: number) => {
    setCart(prev => {
      return prev.map(item => {
        if (item.product.id !== productId) return item
        const newQty = item.quantity + delta
        if (newQty <= 0) return item
        if (newQty > item.product.stock) {
          toast({ title: 'Stock insuffisant', variant: 'destructive' })
          return item
        }
        return { ...item, quantity: newQty }
      }).filter(item => item.quantity > 0)
    })
  }, [])

  // Supprimer du panier
  const removeFromCart = useCallback((productId: string) => {
    setCart(prev => prev.filter(item => item.product.id !== productId))
  }, [])

  // Vider le panier
  const clearCart = useCallback(() => {
    setCart([])
  }, [])

  // Calcul du total
  const total = cart.reduce((sum, item) => sum + item.product.price * item.quantity, 0)
  const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  // Finaliser la commande
  const handleCheckout = async () => {
    if (cart.length === 0) return

    setIsProcessing(true)
    try {
      const result = await createPOSOrder({
        items: cart.map(item => ({
          productId: item.product.id,
          quantity: item.quantity,
          price: item.product.price,
        })),
        paymentMethod,
        notes: notes || undefined,
        customerName: customerName || undefined,
      })

      if (result.success) {
        toast({ title: 'Commande créée avec succès!' })
        setCart([])
        setCheckoutOpen(false)
        setNotes('')
        setCustomerName('')
        setPaymentMethod('CASH')
      } else {
        toast({ title: result.error || 'Erreur lors de la création de la commande', variant: 'destructive' })
      }
    } catch {
      toast({ title: 'Erreur lors de la création de la commande', variant: 'destructive' })
    } finally {
      setIsProcessing(false)
    }
  }

  const formatPrice = (price: number) => {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
    }).format(price)
  }

  return (
    <div className="flex h-full bg-muted/30">
      {/* Zone produits */}
      <div className="flex-1 flex flex-col p-4 overflow-hidden">
        {/* Barre de recherche et filtres */}
        <div className="flex gap-4 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Rechercher un produit..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-10"
            />
          </div>
        </div>

        {/* Onglets catégories */}
        <Tabs value={activeCategory} onValueChange={setActiveCategory} className="flex-1 flex flex-col overflow-hidden">
          <TabsList className="w-full justify-start h-auto flex-wrap gap-1 bg-transparent p-0 mb-4">
            <TabsTrigger 
              value="all"
              className="data-[state=active]:bg-primary data-[state=active]:text-primary-foreground"
            >
              Tous
            </TabsTrigger>
            {categories.map(category => (
              <TabsTrigger 
                key={category.id} 
                value={category.id}
                className="data-[state=active]:bg-primary data-[state=active]:text-primary-foreground"
              >
                {category.name}
              </TabsTrigger>
            ))}
            {uncategorizedProducts.length > 0 && (
              <TabsTrigger 
                value="uncategorized"
                className="data-[state=active]:bg-primary data-[state=active]:text-primary-foreground"
              >
                Sans catégorie
              </TabsTrigger>
            )}
          </TabsList>

          <TabsContent value={activeCategory} className="flex-1 mt-0 overflow-hidden">
            <div className="h-full overflow-y-auto">
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 pb-4">
                {filteredProducts.map(product => (
                  <ProductCard
                    key={product.id}
                    product={product}
                    onClick={() => addToCart(product)}
                    inCart={cart.find(item => item.product.id === product.id)?.quantity}
                  />
                ))}
                {filteredProducts.length === 0 && (
                  <div className="col-span-full text-center py-12 text-muted-foreground">
                    Aucun produit trouvé
                  </div>
                )}
              </div>
            </div>
          </TabsContent>
        </Tabs>
      </div>

      {/* Panier */}
      <div className="w-96 bg-background border-l flex flex-col">
        <div className="p-4 border-b">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold flex items-center gap-2">
              <ShoppingCart className="h-5 w-5" />
              Panier
              {itemCount > 0 && (
                <Badge variant="secondary">{itemCount}</Badge>
              )}
            </h2>
            {cart.length > 0 && (
              <Button variant="ghost" size="sm" onClick={clearCart}>
                <Trash2 className="h-4 w-4 mr-1" />
                Vider
              </Button>
            )}
          </div>
        </div>

        <div className="flex-1 overflow-y-auto">
          <div className="p-4 space-y-3">
            {cart.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <ShoppingCart className="h-12 w-12 mx-auto mb-3 opacity-50" />
                <p>Panier vide</p>
                <p className="text-sm">Cliquez sur un produit pour l&apos;ajouter</p>
              </div>
            ) : (
              cart.map(item => (
                <CartItemCard
                  key={item.product.id}
                  item={item}
                  onUpdateQuantity={(delta) => updateQuantity(item.product.id, delta)}
                  onRemove={() => removeFromCart(item.product.id)}
                  formatPrice={formatPrice}
                />
              ))
            )}
          </div>
        </div>

        {/* Total et actions */}
        <div className="p-4 border-t bg-muted/50 space-y-4">
          <div className="flex items-center justify-between text-lg font-bold">
            <span>Total</span>
            <span>{formatPrice(total)}</span>
          </div>
          <Button 
            className="w-full h-14 text-lg"
            disabled={cart.length === 0}
            onClick={() => setCheckoutOpen(true)}
          >
            <CreditCard className="h-5 w-5 mr-2" />
            Encaisser
          </Button>
        </div>
      </div>

      {/* Dialog de paiement */}
      <Dialog open={checkoutOpen} onOpenChange={setCheckoutOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Finaliser la commande</DialogTitle>
            <DialogDescription>
              Total à encaisser : {formatPrice(total)}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {/* Nom client optionnel */}
            <div className="space-y-2">
              <label className="text-sm font-medium flex items-center gap-2">
                <User className="h-4 w-4" />
                Nom du client (optionnel)
              </label>
              <Input
                placeholder="Ex: Jean Dupont"
                value={customerName}
                onChange={(e) => setCustomerName(e.target.value)}
              />
            </div>

            {/* Mode de paiement */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Mode de paiement</label>
              <div className="grid grid-cols-3 gap-2">
                <Button
                  type="button"
                  variant={paymentMethod === 'CASH' ? 'default' : 'outline'}
                  className="h-16 flex-col gap-1"
                  onClick={() => setPaymentMethod('CASH')}
                >
                  <Banknote className="h-5 w-5" />
                  <span className="text-xs">Espèces</span>
                </Button>
                <Button
                  type="button"
                  variant={paymentMethod === 'CARD' ? 'default' : 'outline'}
                  className="h-16 flex-col gap-1"
                  onClick={() => setPaymentMethod('CARD')}
                >
                  <CreditCard className="h-5 w-5" />
                  <span className="text-xs">Carte</span>
                </Button>
                <Button
                  type="button"
                  variant={paymentMethod === 'SUMUP' ? 'default' : 'outline'}
                  className="h-16 flex-col gap-1"
                  onClick={() => setPaymentMethod('SUMUP')}
                >
                  <CreditCard className="h-5 w-5" />
                  <span className="text-xs">SumUp</span>
                </Button>
              </div>
            </div>

            {/* Notes */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Notes (optionnel)</label>
              <Textarea
                placeholder="Remarques sur la commande..."
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={2}
              />
            </div>

            {/* Récapitulatif */}
            <div className="bg-muted rounded-lg p-3 space-y-2">
              <p className="text-sm font-medium">Récapitulatif</p>
              <div className="text-sm space-y-1">
                {cart.map(item => (
                  <div key={item.product.id} className="flex justify-between">
                    <span>{item.quantity}x {item.product.name}</span>
                    <span>{formatPrice(item.product.price * item.quantity)}</span>
                  </div>
                ))}
              </div>
              <div className="border-t pt-2 flex justify-between font-bold">
                <span>Total</span>
                <span>{formatPrice(total)}</span>
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button variant="outline" onClick={() => setCheckoutOpen(false)}>
              <X className="h-4 w-4 mr-2" />
              Annuler
            </Button>
            <Button onClick={handleCheckout} disabled={isProcessing}>
              {isProcessing ? (
                <>Traitement...</>
              ) : (
                <>
                  <Check className="h-4 w-4 mr-2" />
                  Confirmer
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

// Composant carte produit
function ProductCard({ 
  product, 
  onClick, 
  inCart 
}: { 
  product: Product
  onClick: () => void
  inCart?: number
}) {
  const isLowStock = product.stock > 0 && product.stock <= 5
  const isOutOfStock = product.stock <= 0

  return (
    <Card 
      className={cn(
        "cursor-pointer transition-all hover:shadow-md hover:scale-[1.02] relative overflow-hidden",
        isOutOfStock && "opacity-50 cursor-not-allowed",
        inCart && "ring-2 ring-primary"
      )}
      onClick={isOutOfStock ? undefined : onClick}
    >
      {inCart && (
        <Badge className="absolute top-2 right-2 z-10 bg-primary">
          {inCart}
        </Badge>
      )}
      <div className="aspect-square relative bg-muted">
        {product.image ? (
          <Image
            src={product.image}
            alt={product.name}
            fill
            className="object-cover"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center">
            <Package className="h-8 w-8 text-muted-foreground" />
          </div>
        )}
        {isLowStock && !isOutOfStock && (
          <Badge variant="destructive" className="absolute bottom-2 left-2 text-xs">
            Stock: {product.stock}
          </Badge>
        )}
        {isOutOfStock && (
          <div className="absolute inset-0 bg-background/80 flex items-center justify-center">
            <Badge variant="destructive">Rupture</Badge>
          </div>
        )}
      </div>
      <CardContent className="p-3">
        <p className="font-medium text-sm truncate">{product.name}</p>
        <p className="text-primary font-bold">
          {new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR',
          }).format(product.price)}
        </p>
      </CardContent>
    </Card>
  )
}

// Composant item panier
function CartItemCard({ 
  item, 
  onUpdateQuantity, 
  onRemove,
  formatPrice,
}: { 
  item: CartItem
  onUpdateQuantity: (delta: number) => void
  onRemove: () => void
  formatPrice: (price: number) => string
}) {
  return (
    <Card>
      <CardContent className="p-3">
        <div className="flex gap-3">
          <div className="w-12 h-12 rounded-lg bg-muted overflow-hidden flex-shrink-0">
            {item.product.image ? (
              <Image
                src={item.product.image}
                alt={item.product.name}
                width={48}
                height={48}
                className="object-cover w-full h-full"
              />
            ) : (
              <div className="w-full h-full flex items-center justify-center">
                <Package className="h-5 w-5 text-muted-foreground" />
              </div>
            )}
          </div>
          <div className="flex-1 min-w-0">
            <p className="font-medium text-sm truncate">{item.product.name}</p>
            <p className="text-sm text-muted-foreground">
              {formatPrice(item.product.price)} × {item.quantity}
            </p>
          </div>
          <div className="text-right">
            <p className="font-bold">{formatPrice(item.product.price * item.quantity)}</p>
          </div>
        </div>
        <div className="flex items-center justify-between mt-3">
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8"
              onClick={() => onUpdateQuantity(-1)}
            >
              <Minus className="h-3 w-3" />
            </Button>
            <span className="w-8 text-center font-medium">{item.quantity}</span>
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8"
              onClick={() => onUpdateQuantity(1)}
              disabled={item.quantity >= item.product.stock}
            >
              <Plus className="h-3 w-3" />
            </Button>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-destructive hover:text-destructive"
            onClick={onRemove}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
