'use client'

import { useState, useRef, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import Image from 'next/image'
import {
  Upload,
  Trash2,
  Copy,
  Check,
  FileImage,
  FileText,
  File,
  X,
  Loader2,
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/app/components/ui/dialog'
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
import { formatDate, formatFileSize } from '@/app/lib/utils'
import { deleteMedia } from '@/app/actions/media.actions'
import type { Media } from '@prisma/client'

interface MediaLibraryProps {
  media: Media[]
}

function getFileIcon(mimeType: string) {
  if (mimeType.startsWith('image/')) return FileImage
  if (mimeType.startsWith('text/') || mimeType.includes('pdf')) return FileText
  return File
}

export function MediaLibrary({ media: initialMedia }: MediaLibraryProps) {
  const router = useRouter()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [media, setMedia] = useState(initialMedia)
  const [selectedMedia, setSelectedMedia] = useState<Media | null>(null)
  const [deleteId, setDeleteId] = useState<string | null>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [isDeleting, setIsDeleting] = useState(false)
  const [copied, setCopied] = useState(false)
  const [dragActive, setDragActive] = useState(false)

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)

    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      handleUpload(e.dataTransfer.files)
    }
  }, [])

  const handleUpload = async (files: FileList) => {
    setIsUploading(true)

    try {
      const formData = new FormData()
      for (let i = 0; i < files.length; i++) {
        formData.append('files', files[i])
      }

      const response = await fetch('/api/upload', {
        method: 'POST',
        body: formData,
      })

      if (!response.ok) {
        throw new Error('Upload failed')
      }

      const result = await response.json()
      setMedia((prev) => [...result.media, ...prev])
      toast({
        title: 'Fichiers uploadés',
        description: `${result.media.length} fichier(s) uploadé(s)`,
        variant: 'success',
      })
      router.refresh()
    } catch {
      toast({
        title: 'Erreur',
        description: "Impossible d'uploader les fichiers",
        variant: 'destructive',
      })
    } finally {
      setIsUploading(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteId) return

    setIsDeleting(true)
    try {
      const result = await deleteMedia(deleteId)
      if (result.success) {
        setMedia((prev) => prev.filter((m) => m.id !== deleteId))
        toast({
          title: 'Fichier supprimé',
          variant: 'success',
        })
        router.refresh()
      } else {
        toast({
          title: 'Erreur',
          description: result.error,
          variant: 'destructive',
        })
      }
    } catch {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsDeleting(false)
      setDeleteId(null)
    }
  }

  const copyUrl = async (url: string) => {
    await navigator.clipboard.writeText(url)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
    toast({
      title: 'URL copiée',
      variant: 'success',
    })
  }

  return (
    <>
      {/* Zone de drop */}
      <div
        className={`border-2 border-dashed rounded-lg p-8 text-center mb-6 transition-colors ${
          dragActive
            ? 'border-primary bg-primary/5'
            : 'border-muted-foreground/25 hover:border-primary'
        }`}
        onDragEnter={handleDrag}
        onDragLeave={handleDrag}
        onDragOver={handleDrag}
        onDrop={handleDrop}
      >
        <input
          ref={fileInputRef}
          type="file"
          multiple
          accept="image/*,.pdf,.doc,.docx"
          className="hidden"
          onChange={(e) => e.target.files && handleUpload(e.target.files)}
        />
        <Upload className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
        <p className="text-lg font-medium mb-2">
          Glissez-déposez vos fichiers ici
        </p>
        <p className="text-sm text-muted-foreground mb-4">
          ou cliquez pour sélectionner
        </p>
        <Button
          onClick={() => fileInputRef.current?.click()}
          disabled={isUploading}
        >
          {isUploading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          {isUploading ? 'Upload en cours...' : 'Sélectionner des fichiers'}
        </Button>
      </div>

      {/* Grille des médias */}
      {media.length > 0 ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
          {media.map((item) => {
            const isImage = item.mimeType.startsWith('image/')
            const Icon = getFileIcon(item.mimeType)

            return (
              <div
                key={item.id}
                className="group relative aspect-square border rounded-lg overflow-hidden cursor-pointer hover:ring-2 hover:ring-primary transition-all"
                onClick={() => setSelectedMedia(item)}
              >
                {isImage ? (
                  <Image
                    src={item.url}
                    alt={item.alt || item.name}
                    fill
                    className="object-cover"
                  />
                ) : (
                  <div className="h-full flex flex-col items-center justify-center p-2 bg-muted">
                    <Icon className="h-8 w-8 mb-2 text-muted-foreground" />
                    <p className="text-xs text-center truncate w-full">
                      {item.name}
                    </p>
                  </div>
                )}

                {/* Overlay au hover */}
                <div className="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                  <Button
                    size="icon"
                    variant="secondary"
                    onClick={(e) => {
                      e.stopPropagation()
                      copyUrl(item.url)
                    }}
                  >
                    {copied ? (
                      <Check className="h-4 w-4" />
                    ) : (
                      <Copy className="h-4 w-4" />
                    )}
                  </Button>
                  <Button
                    size="icon"
                    variant="destructive"
                    onClick={(e) => {
                      e.stopPropagation()
                      setDeleteId(item.id)
                    }}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )
          })}
        </div>
      ) : (
        <div className="text-center py-12">
          <FileImage className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
          <p className="text-muted-foreground">Aucun média</p>
        </div>
      )}

      {/* Dialog détails du média */}
      <Dialog
        open={!!selectedMedia}
        onOpenChange={() => setSelectedMedia(null)}
      >
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{selectedMedia?.name}</DialogTitle>
            <DialogDescription>Détails du fichier</DialogDescription>
          </DialogHeader>

          {selectedMedia && (
            <div className="space-y-4">
              {selectedMedia.mimeType.startsWith('image/') && (
                <div className="relative aspect-video rounded-lg overflow-hidden bg-muted">
                  <Image
                    src={selectedMedia.url}
                    alt={selectedMedia.alt || selectedMedia.name}
                    fill
                    className="object-contain"
                  />
                </div>
              )}

              <div className="grid gap-2">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium">URL :</span>
                  <Input
                    value={selectedMedia.url}
                    readOnly
                    className="flex-1"
                  />
                  <Button
                    size="icon"
                    variant="outline"
                    onClick={() => copyUrl(selectedMedia.url)}
                  >
                    {copied ? (
                      <Check className="h-4 w-4" />
                    ) : (
                      <Copy className="h-4 w-4" />
                    )}
                  </Button>
                </div>

                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span className="font-medium">Type :</span>{' '}
                    <span className="text-muted-foreground">
                      {selectedMedia.mimeType}
                    </span>
                  </div>
                  <div>
                    <span className="font-medium">Taille :</span>{' '}
                    <span className="text-muted-foreground">
                      {formatFileSize(selectedMedia.size)}
                    </span>
                  </div>
                  <div>
                    <span className="font-medium">Date :</span>{' '}
                    <span className="text-muted-foreground">
                      {formatDate(selectedMedia.createdAt)}
                    </span>
                  </div>
                </div>
              </div>

              <div className="flex justify-end gap-2">
                <Button
                  variant="outline"
                  onClick={() => setSelectedMedia(null)}
                >
                  <X className="mr-2 h-4 w-4" />
                  Fermer
                </Button>
                <Button
                  variant="destructive"
                  onClick={() => {
                    setSelectedMedia(null)
                    setDeleteId(selectedMedia.id)
                  }}
                >
                  <Trash2 className="mr-2 h-4 w-4" />
                  Supprimer
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* Dialog de suppression */}
      <AlertDialog open={!!deleteId} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer ce fichier ?</AlertDialogTitle>
            <AlertDialogDescription>
              Cette action est irréversible. Le fichier sera définitivement
              supprimé.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isDeleting}>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={isDeleting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isDeleting ? 'Suppression...' : 'Supprimer'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
