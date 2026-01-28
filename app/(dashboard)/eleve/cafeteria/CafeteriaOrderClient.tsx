'use client'

import { useState } from 'react'
import Image from 'next/image'
import { 
  ShoppingCart, 
  Plus, 
  Minus, 
  Trash2, 
  Coffee,
  Package,
  CreditCard,
  CheckCircle2,
  Loader2,
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Textarea } from '@/app/components/ui/textarea'
import { Label } from '@/app/components/ui/label'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/app/components/ui/sheet'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/app/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/app/components/ui/tabs'
import { toast } from '@/app/components/ui/use-toast'
import { createCafeteriaOrder } from '@/app/actions/orders.actions'
import { formatPrice } from '@/app/lib/utils'

type ProductCategory = {
  id: string
  name: string
  description: string | null
  image: string | null
  order: number
  isActive: boolean
  createdAt: Date
  updatedAt: Date
}

type SerializedProduct = {
  id: string
  name: string
  description: string | null
  price: number
  image: string | null
  categoryId: string | null
  stock: number
  isAvailable: boolean
  isActive: boolean
  order: number
  createdAt: Date
  updatedAt: Date
  category: ProductCategory | null
}

type CategoryWithCount = ProductCategory & {
  _count: { products: number }
}

interface CartItem {
  product: SerializedProduct
  quantity: number
}

interface CafeteriaOrderClientProps {
  products: SerializedProduct[]
  categories: CategoryWithCount[]
}

export function CafeteriaOrderClient({ products, categories }: CafeteriaOrderClientProps) {
  const [cart, setCart] = useState<CartItem[]>([])
  const [notes, setNotes] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [showSuccessDialog, setShowSuccessDialog] = useState(false)
  const [activeCategory, setActiveCategory] = useState<string>('all')

  // Filtrer les produits par catégorie
  const filteredProducts = activeCategory === 'all' 
    ? products 
    : products.filter(p => p.categoryId === activeCategory)

  // Ajouter au panier
  const addToCart = (product: SerializedProduct) => {
    setCart(prev => {
      const existing = prev.find(item => item.product.id === product.id)
      if (existing) {
        // Vérifier le stock
        if (existing.quantity >= product.stock) {
          toast({
            title: 'Stock insuffisant',
            description: `Il ne reste que ${product.stock} ${product.name} en stock`,
            variant: 'destructive',
          })
          return prev
        }
        return prev.map(item =>
          item.product.id === product.id
            ? { ...item, quantity: item.quantity + 1 }
            : item
        )
      }
      return [...prev, { product, quantity: 1 }]
    })
  }

  // Retirer du panier
  const removeFromCart = (productId: string) => {
    setCart(prev => prev.filter(item => item.product.id !== productId))
  }

  // Modifier la quantité
  const updateQuantity = (productId: string, delta: number) => {
    setCart(prev => {
      return prev.map(item => {
        if (item.product.id !== productId) return item
        const newQuantity = item.quantity + delta
        if (newQuantity <= 0) return item
        if (newQuantity > item.product.stock) {
          toast({
            title: 'Stock insuffisant',
            description: `Il ne reste que ${item.product.stock} ${item.product.name} en stock`,
            variant: 'destructive',
          })
          return item
        }
        return { ...item, quantity: newQuantity }
      })
    })
  }

  // Calculer le total
  const total = cart.reduce((sum, item) => sum + item.product.price * item.quantity, 0)
  const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0)

  // Passer la commande
  const handleOrder = async () => {
    if (cart.length === 0) return

    setIsSubmitting(true)
    try {
      const items = cart.map(item => ({
        productId: item.product.id,
        quantity: item.quantity,
        price: item.product.price,
      }))

      const result = await createCafeteriaOrder({ items, notes })

      if (result.success) {
        setCart([])
        setNotes('')
        setShowSuccessDialog(true)
      } else {
        toast({
          title: 'Erreur',
          description: result.error || 'Une erreur est survenue',
          variant: 'destructive',
        })
      }
    } catch (error) {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsSubmitting(false)
    }
  }

  // Obtenir la quantité dans le panier
  const getCartQuantity = (productId: string) => {
    return cart.find(item => item.product.id === productId)?.quantity || 0
  }

  return (
    <>
      <div className="flex gap-6">
        {/* Liste des produits */}
        <div className="flex-1 space-y-6">
          {/* Filtres par catégorie */}
          <Tabs value={activeCategory} onValueChange={setActiveCategory}>
            <TabsList className="w-full justify-start overflow-x-auto">
              <TabsTrigger value="all">
                Tous
              </TabsTrigger>
              {categories.filter(c => c.isActive).map(category => (
                <TabsTrigger key={category.id} value={category.id}>
                  {category.name}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>

          {/* Grille des produits */}
          {filteredProducts.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {filteredProducts.map(product => {
                const cartQty = getCartQuantity(product.id)
                const isOutOfStock = product.stock <= 0
                const isLowStock = product.stock > 0 && product.stock <= 5

                return (
                  <Card key={product.id} className="overflow-hidden group">
                    {/* Image */}
                    <div className="relative aspect-[4/3] bg-muted">
                      {product.image ? (
                        <Image
                          src={product.image}
                          alt={product.name}
                          fill
                          className="object-cover transition-transform group-hover:scale-105"
                        />
                      ) : (
                        <div className="absolute inset-0 flex items-center justify-center">
                          <Coffee className="h-12 w-12 text-muted-foreground/50" />
                        </div>
                      )}
                      {isOutOfStock && (
                        <div className="absolute inset-0 bg-black/60 flex items-center justify-center">
                          <Badge variant="destructive" className="text-sm">
                            Rupture de stock
                          </Badge>
                        </div>
                      )}
                      {isLowStock && !isOutOfStock && (
                        <Badge 
                          variant="warning" 
                          className="absolute top-2 right-2"
                        >
                          Plus que {product.stock}
                        </Badge>
                      )}
                      {cartQty > 0 && (
                        <Badge 
                          className="absolute top-2 left-2 bg-gradient-to-r from-blue-500 to-violet-500"
                        >
                          {cartQty} dans le panier
                        </Badge>
                      )}
                    </div>

                    <CardContent className="p-4">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <h3 className="font-semibold">{product.name}</h3>
                          {product.description && (
                            <p className="text-sm text-muted-foreground line-clamp-2 mt-1">
                              {product.description}
                            </p>
                          )}
                        </div>
                        <span className="font-bold text-lg whitespace-nowrap">
                          {formatPrice(product.price)}
                        </span>
                      </div>
                    </CardContent>

                    <CardFooter className="p-4 pt-0">
                      {cartQty > 0 ? (
                        <div className="flex items-center justify-between w-full">
                          <Button
                            size="icon"
                            variant="outline"
                            onClick={() => updateQuantity(product.id, -1)}
                          >
                            <Minus className="h-4 w-4" />
                          </Button>
                          <span className="font-semibold">{cartQty}</span>
                          <Button
                            size="icon"
                            variant="outline"
                            onClick={() => updateQuantity(product.id, 1)}
                            disabled={cartQty >= product.stock}
                          >
                            <Plus className="h-4 w-4" />
                          </Button>
                          <Button
                            size="icon"
                            variant="destructive"
                            onClick={() => removeFromCart(product.id)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      ) : (
                        <Button
                          className="w-full bg-gradient-to-r from-blue-500 to-violet-500 hover:from-blue-600 hover:to-violet-600"
                          onClick={() => addToCart(product)}
                          disabled={isOutOfStock}
                        >
                          <Plus className="h-4 w-4 mr-2" />
                          Ajouter
                        </Button>
                      )}
                    </CardFooter>
                  </Card>
                )
              })}
            </div>
          ) : (
            <div className="flex flex-col items-center justify-center py-16 border rounded-xl bg-card">
              <Package className="h-12 w-12 text-muted-foreground/50 mb-4" />
              <p className="text-muted-foreground">
                Aucun produit disponible dans cette catégorie
              </p>
            </div>
          )}
        </div>

        {/* Panier (Desktop) */}
        <div className="hidden lg:block w-80">
          <Card className="sticky top-20">
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2">
                <ShoppingCart className="h-5 w-5" />
                Votre panier
                {totalItems > 0 && (
                  <Badge variant="secondary">{totalItems}</Badge>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {cart.length > 0 ? (
                <>
                  <div className="space-y-3 max-h-[300px] overflow-y-auto">
                    {cart.map(item => (
                      <div key={item.product.id} className="flex items-center gap-3">
                        <div className="relative h-12 w-12 rounded-lg overflow-hidden bg-muted flex-shrink-0">
                          {item.product.image ? (
                            <Image
                              src={item.product.image}
                              alt={item.product.name}
                              fill
                              className="object-cover"
                            />
                          ) : (
                            <div className="flex items-center justify-center h-full">
                              <Coffee className="h-5 w-5 text-muted-foreground/50" />
                            </div>
                          )}
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="font-medium text-sm truncate">{item.product.name}</p>
                          <p className="text-sm text-muted-foreground">
                            {formatPrice(item.product.price)} × {item.quantity}
                          </p>
                        </div>
                        <Button
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8"
                          onClick={() => removeFromCart(item.product.id)}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="notes">Notes (optionnel)</Label>
                    <Textarea
                      id="notes"
                      placeholder="Instructions spéciales..."
                      value={notes}
                      onChange={(e) => setNotes(e.target.value)}
                      rows={2}
                    />
                  </div>

                  <div className="border-t pt-4">
                    <div className="flex justify-between items-center mb-4">
                      <span className="font-semibold">Total</span>
                      <span className="text-xl font-bold">{formatPrice(total)}</span>
                    </div>
                    <Button
                      className="w-full bg-gradient-to-r from-blue-500 to-violet-500 hover:from-blue-600 hover:to-violet-600"
                      onClick={handleOrder}
                      disabled={isSubmitting}
                    >
                      {isSubmitting ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Commande en cours...
                        </>
                      ) : (
                        <>
                          <CreditCard className="h-4 w-4 mr-2" />
                          Passer la commande
                        </>
                      )}
                    </Button>
                  </div>
                </>
              ) : (
                <div className="py-8 text-center text-muted-foreground">
                  <ShoppingCart className="h-8 w-8 mx-auto mb-2 opacity-50" />
                  <p>Votre panier est vide</p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Bouton panier flottant (Mobile) */}
      {totalItems > 0 && (
        <div className="fixed bottom-4 left-4 right-4 lg:hidden z-50">
          <Sheet>
            <SheetTrigger asChild>
              <Button className="w-full bg-gradient-to-r from-blue-500 to-violet-500 h-14 text-lg shadow-lg">
                <ShoppingCart className="h-5 w-5 mr-2" />
                Voir le panier ({totalItems}) • {formatPrice(total)}
              </Button>
            </SheetTrigger>
            <SheetContent side="bottom" className="h-[80vh]">
              <SheetHeader>
                <SheetTitle className="flex items-center gap-2">
                  <ShoppingCart className="h-5 w-5" />
                  Votre panier
                </SheetTitle>
                <SheetDescription>
                  {totalItems} article{totalItems > 1 ? 's' : ''} dans votre panier
                </SheetDescription>
              </SheetHeader>
              <div className="py-4 space-y-4 overflow-y-auto max-h-[50vh]">
                {cart.map(item => (
                  <div key={item.product.id} className="flex items-center gap-3">
                    <div className="relative h-16 w-16 rounded-lg overflow-hidden bg-muted flex-shrink-0">
                      {item.product.image ? (
                        <Image
                          src={item.product.image}
                          alt={item.product.name}
                          fill
                          className="object-cover"
                        />
                      ) : (
                        <div className="flex items-center justify-center h-full">
                          <Coffee className="h-6 w-6 text-muted-foreground/50" />
                        </div>
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="font-medium truncate">{item.product.name}</p>
                      <p className="text-sm text-muted-foreground">
                        {formatPrice(item.product.price)} × {item.quantity}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        size="icon"
                        variant="outline"
                        className="h-8 w-8"
                        onClick={() => updateQuantity(item.product.id, -1)}
                      >
                        <Minus className="h-3 w-3" />
                      </Button>
                      <span className="w-6 text-center">{item.quantity}</span>
                      <Button
                        size="icon"
                        variant="outline"
                        className="h-8 w-8"
                        onClick={() => updateQuantity(item.product.id, 1)}
                        disabled={item.quantity >= item.product.stock}
                      >
                        <Plus className="h-3 w-3" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label htmlFor="notes-mobile">Notes (optionnel)</Label>
                  <Textarea
                    id="notes-mobile"
                    placeholder="Instructions spéciales..."
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    rows={2}
                  />
                </div>
              </div>
              <SheetFooter className="mt-4">
                <div className="w-full space-y-3">
                  <div className="flex justify-between items-center">
                    <span className="font-semibold">Total</span>
                    <span className="text-xl font-bold">{formatPrice(total)}</span>
                  </div>
                  <Button
                    className="w-full bg-gradient-to-r from-blue-500 to-violet-500 hover:from-blue-600 hover:to-violet-600 h-12"
                    onClick={handleOrder}
                    disabled={isSubmitting}
                  >
                    {isSubmitting ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Commande en cours...
                      </>
                    ) : (
                      <>
                        <CreditCard className="h-4 w-4 mr-2" />
                        Passer la commande
                      </>
                    )}
                  </Button>
                </div>
              </SheetFooter>
            </SheetContent>
          </Sheet>
        </div>
      )}

      {/* Dialog de succès */}
      <Dialog open={showSuccessDialog} onOpenChange={setShowSuccessDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-green-600">
              <CheckCircle2 className="h-6 w-6" />
              Commande confirmée !
            </DialogTitle>
            <DialogDescription>
              Votre commande a été enregistrée avec succès. Vous pouvez suivre son statut dans vos commandes.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button onClick={() => setShowSuccessDialog(false)}>
              Continuer mes achats
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
