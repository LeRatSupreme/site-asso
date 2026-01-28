'use client'

import { useState } from 'react'
import Link from 'next/link'
import Image from 'next/image'
import { 
  Edit, 
  Trash2, 
  Plus, 
  Minus, 
  MoreHorizontal,
  Eye,
  EyeOff,
  Package,
  ImageIcon
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { Input } from '@/app/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/app/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/app/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/app/components/ui/alert-dialog'
import { toast } from '@/app/components/ui/use-toast'
import { 
  deleteProduct, 
  updateStock, 
  toggleProductAvailability,
  toggleProductActive 
} from '@/app/actions/cafeteria.actions'
import { cn } from '@/app/lib/utils'
import type { Product, ProductCategory } from '@prisma/client'

type ProductWithCategory = Product & {
  category: ProductCategory | null
}

type CategoryWithCount = ProductCategory & {
  _count: { products: number }
}

interface ProductsTableProps {
  products: ProductWithCategory[]
  categories: CategoryWithCount[]
}

export function ProductsTable({ products, categories }: ProductsTableProps) {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedProduct, setSelectedProduct] = useState<ProductWithCategory | null>(null)
  const [isLoading, setIsLoading] = useState<string | null>(null)
  const [filter, setFilter] = useState<string>('all')
  const [search, setSearch] = useState('')

  const filteredProducts = products.filter((product) => {
    const matchesSearch = product.name.toLowerCase().includes(search.toLowerCase())
    const matchesFilter = 
      filter === 'all' ||
      (filter === 'available' && product.isAvailable && product.stock > 0) ||
      (filter === 'out-of-stock' && product.stock === 0) ||
      (filter === 'low-stock' && product.stock > 0 && product.stock <= 5) ||
      (filter === 'inactive' && !product.isActive) ||
      filter === product.categoryId
    return matchesSearch && matchesFilter
  })

  const handleDelete = async () => {
    if (!selectedProduct) return

    setIsLoading(selectedProduct.id)
    const result = await deleteProduct(selectedProduct.id)
    setIsLoading(null)

    if (result.success) {
      toast({
        title: 'Produit supprimé',
        description: 'Le produit a été supprimé avec succès',
        variant: 'success',
      })
    } else {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    }

    setDeleteDialogOpen(false)
    setSelectedProduct(null)
  }

  const handleStockChange = async (id: string, newStock: number) => {
    if (newStock < 0) return
    
    setIsLoading(id)
    const result = await updateStock(id, newStock)
    setIsLoading(null)

    if (!result.success) {
      toast({
        title: 'Erreur',
        description: result.error || 'Une erreur est survenue',
        variant: 'destructive',
      })
    }
  }

  const handleToggleAvailability = async (id: string) => {
    setIsLoading(id)
    const result = await toggleProductAvailability(id)
    setIsLoading(null)

    if (result.success) {
      toast({
        title: 'Disponibilité mise à jour',
        variant: 'success',
      })
    }
  }

  const handleToggleActive = async (id: string) => {
    setIsLoading(id)
    const result = await toggleProductActive(id)
    setIsLoading(null)

    if (result.success) {
      toast({
        title: 'Statut mis à jour',
        variant: 'success',
      })
    }
  }

  const formatPrice = (price: unknown) => {
    const numPrice = typeof price === 'object' ? Number(price) : Number(price)
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: 'EUR',
    }).format(numPrice)
  }

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-4">
        <Input
          placeholder="Rechercher un produit..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="sm:max-w-xs"
        />
        <div className="flex gap-2 flex-wrap">
          <Button
            variant={filter === 'all' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilter('all')}
          >
            Tous ({products.length})
          </Button>
          <Button
            variant={filter === 'available' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilter('available')}
          >
            Disponibles
          </Button>
          <Button
            variant={filter === 'low-stock' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilter('low-stock')}
            className={filter !== 'low-stock' ? 'text-amber-600 border-amber-300' : ''}
          >
            Stock faible
          </Button>
          <Button
            variant={filter === 'out-of-stock' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilter('out-of-stock')}
            className={filter !== 'out-of-stock' ? 'text-red-600 border-red-300' : ''}
          >
            Rupture
          </Button>
        </div>
      </div>

      {/* Table */}
      {filteredProducts.length === 0 ? (
        <div className="text-center py-12">
          <Package className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
          <h3 className="text-lg font-semibold">Aucun produit</h3>
          <p className="text-muted-foreground mb-4">
            {search ? 'Aucun produit ne correspond à votre recherche' : 'Commencez par ajouter un produit'}
          </p>
          {!search && (
            <Button asChild>
              <Link href="/admin/cafeteria/new">
                <Plus className="h-4 w-4 mr-2" />
                Ajouter un produit
              </Link>
            </Button>
          )}
        </div>
      ) : (
        <div className="rounded-xl border overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/50">
                <TableHead className="w-[80px]">Image</TableHead>
                <TableHead>Produit</TableHead>
                <TableHead>Catégorie</TableHead>
                <TableHead className="text-right">Prix</TableHead>
                <TableHead className="text-center">Stock</TableHead>
                <TableHead className="text-center">Statut</TableHead>
                <TableHead className="w-[80px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredProducts.map((product) => (
                <TableRow 
                  key={product.id}
                  className={cn(
                    !product.isActive && 'opacity-50',
                    product.stock === 0 && 'bg-red-50/50 dark:bg-red-950/20'
                  )}
                >
                  <TableCell>
                    <div className="relative h-12 w-12 rounded-lg overflow-hidden bg-muted">
                      {product.image ? (
                        <Image
                          src={product.image}
                          alt={product.name}
                          fill
                          className="object-cover"
                        />
                      ) : (
                        <div className="flex items-center justify-center h-full">
                          <ImageIcon className="h-5 w-5 text-muted-foreground" />
                        </div>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div>
                      <p className="font-medium">{product.name}</p>
                      {product.description && (
                        <p className="text-sm text-muted-foreground line-clamp-1">
                          {product.description}
                        </p>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    {product.category ? (
                      <Badge variant="secondary">{product.category.name}</Badge>
                    ) : (
                      <span className="text-muted-foreground text-sm">-</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right font-semibold">
                    {formatPrice(product.price)}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-center gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => handleStockChange(product.id, product.stock - 1)}
                        disabled={isLoading === product.id || product.stock === 0}
                      >
                        <Minus className="h-4 w-4" />
                      </Button>
                      <span className={cn(
                        'w-10 text-center font-medium',
                        product.stock === 0 && 'text-red-500',
                        product.stock > 0 && product.stock <= 5 && 'text-amber-500'
                      )}>
                        {product.stock}
                      </span>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => handleStockChange(product.id, product.stock + 1)}
                        disabled={isLoading === product.id}
                      >
                        <Plus className="h-4 w-4" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell className="text-center">
                    <div className="flex flex-col items-center gap-1">
                      {!product.isActive ? (
                        <Badge variant="ghost">Inactif</Badge>
                      ) : product.stock === 0 ? (
                        <Badge variant="destructive">Rupture</Badge>
                      ) : product.isAvailable ? (
                        <Badge variant="success">Disponible</Badge>
                      ) : (
                        <Badge variant="warning">Indisponible</Badge>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                          <MoreHorizontal className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                          <Link href={`/admin/cafeteria/${product.id}/edit`}>
                            <Edit className="h-4 w-4 mr-2" />
                            Modifier
                          </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => handleToggleAvailability(product.id)}>
                          {product.isAvailable ? (
                            <>
                              <EyeOff className="h-4 w-4 mr-2" />
                              Marquer indisponible
                            </>
                          ) : (
                            <>
                              <Eye className="h-4 w-4 mr-2" />
                              Marquer disponible
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => handleToggleActive(product.id)}>
                          {product.isActive ? (
                            <>
                              <EyeOff className="h-4 w-4 mr-2" />
                              Désactiver
                            </>
                          ) : (
                            <>
                              <Eye className="h-4 w-4 mr-2" />
                              Activer
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          className="text-red-600"
                          onClick={() => {
                            setSelectedProduct(product)
                            setDeleteDialogOpen(true)
                          }}
                        >
                          <Trash2 className="h-4 w-4 mr-2" />
                          Supprimer
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {/* Delete Dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer ce produit ?</AlertDialogTitle>
            <AlertDialogDescription>
              Cette action est irréversible. Le produit &quot;{selectedProduct?.name}&quot; 
              sera définitivement supprimé.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-red-600 hover:bg-red-700"
            >
              Supprimer
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
