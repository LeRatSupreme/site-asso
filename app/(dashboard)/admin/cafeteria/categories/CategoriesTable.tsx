'use client'

import { useState } from 'react'
import Link from 'next/link'
import Image from 'next/image'
import { Edit, Trash2, Plus, MoreHorizontal, Tags, ImageIcon } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
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
import { deleteCategory } from '@/app/actions/cafeteria.actions'
import type { ProductCategory } from '@prisma/client'

type CategoryWithCount = ProductCategory & {
  _count: { products: number }
}

interface CategoriesTableProps {
  categories: CategoryWithCount[]
}

export function CategoriesTable({ categories }: CategoriesTableProps) {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedCategory, setSelectedCategory] = useState<CategoryWithCount | null>(null)
  const [isLoading, setIsLoading] = useState(false)

  const handleDelete = async () => {
    if (!selectedCategory) return

    setIsLoading(true)
    const result = await deleteCategory(selectedCategory.id)
    setIsLoading(false)

    if (result.success) {
      toast({
        title: 'Catégorie supprimée',
        description: 'La catégorie a été supprimée avec succès',
        variant: 'success',
      })
    } else {
      toast({
        title: 'Erreur',
        description: result.error || 'Une erreur est survenue',
        variant: 'destructive',
      })
    }

    setDeleteDialogOpen(false)
    setSelectedCategory(null)
  }

  if (categories.length === 0) {
    return (
      <div className="text-center py-12">
        <Tags className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
        <h3 className="text-lg font-semibold">Aucune catégorie</h3>
        <p className="text-muted-foreground mb-4">
          Créez des catégories pour organiser vos produits
        </p>
        <Button asChild>
          <Link href="/admin/cafeteria/categories/new">
            <Plus className="h-4 w-4 mr-2" />
            Créer une catégorie
          </Link>
        </Button>
      </div>
    )
  }

  return (
    <>
      <div className="rounded-xl border overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/50">
              <TableHead className="w-[80px]">Image</TableHead>
              <TableHead>Nom</TableHead>
              <TableHead>Description</TableHead>
              <TableHead className="text-center">Produits</TableHead>
              <TableHead className="text-center">Ordre</TableHead>
              <TableHead className="text-center">Statut</TableHead>
              <TableHead className="w-[80px]"></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {categories.map((category) => (
              <TableRow key={category.id}>
                <TableCell>
                  <div className="relative h-12 w-12 rounded-lg overflow-hidden bg-muted">
                    {category.image ? (
                      <Image
                        src={category.image}
                        alt={category.name}
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
                <TableCell className="font-medium">{category.name}</TableCell>
                <TableCell>
                  {category.description ? (
                    <span className="text-sm text-muted-foreground line-clamp-1">
                      {category.description}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">-</span>
                  )}
                </TableCell>
                <TableCell className="text-center">
                  <Badge variant="secondary">{category._count.products}</Badge>
                </TableCell>
                <TableCell className="text-center">{category.order}</TableCell>
                <TableCell className="text-center">
                  <Badge variant={category.isActive ? 'success' : 'ghost'}>
                    {category.isActive ? 'Active' : 'Inactive'}
                  </Badge>
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
                        <Link href={`/admin/cafeteria/categories/${category.id}/edit`}>
                          <Edit className="h-4 w-4 mr-2" />
                          Modifier
                        </Link>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        className="text-red-600"
                        onClick={() => {
                          setSelectedCategory(category)
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

      {/* Delete Dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer cette catégorie ?</AlertDialogTitle>
            <AlertDialogDescription>
              {selectedCategory && selectedCategory._count.products > 0 ? (
                <>
                  Cette catégorie contient <strong>{selectedCategory._count.products} produit(s)</strong>. 
                  Vous devez d&apos;abord déplacer ou supprimer ces produits.
                </>
              ) : (
                <>
                  Cette action est irréversible. La catégorie &quot;{selectedCategory?.name}&quot; 
                  sera définitivement supprimée.
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-red-600 hover:bg-red-700"
              disabled={isLoading || (selectedCategory?._count.products || 0) > 0}
            >
              Supprimer
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
