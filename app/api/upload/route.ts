import { NextRequest, NextResponse } from 'next/server'
import { writeFile, mkdir } from 'fs/promises'
import { join } from 'path'
import { v4 as uuidv4 } from 'uuid'
import { auth } from '@/app/lib/auth'
import { prisma } from '@/app/lib/prisma'

// Types de fichiers autorisés
const ALLOWED_TYPES = [
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'image/svg+xml',
  'application/pdf',
]

const MAX_FILE_SIZE = 5 * 1024 * 1024 // 5MB

export async function POST(request: NextRequest) {
  try {
    // Vérifier l'authentification
    const session = await auth()
    if (!session?.user || session.user.role !== 'ADMIN') {
      return NextResponse.json({ error: 'Non autorisé' }, { status: 401 })
    }

    const formData = await request.formData()
    const files = formData.getAll('files') as File[]

    if (!files || files.length === 0) {
      return NextResponse.json({ error: 'Aucun fichier fourni' }, { status: 400 })
    }

    const uploadedMedia = []

    // Créer le dossier uploads s'il n'existe pas
    const uploadDir = join(process.cwd(), 'public', 'uploads')
    try {
      await mkdir(uploadDir, { recursive: true })
    } catch {
      // Le dossier existe déjà
    }

    for (const file of files) {
      // Vérifier le type
      if (!ALLOWED_TYPES.includes(file.type)) {
        continue // Ignorer les fichiers non autorisés
      }

      // Vérifier la taille
      if (file.size > MAX_FILE_SIZE) {
        continue // Ignorer les fichiers trop gros
      }

      // Générer un nom unique
      const extension = file.name.split('.').pop()
      const filename = `${uuidv4()}.${extension}`
      const filepath = join(uploadDir, filename)

      // Sauvegarder le fichier
      const bytes = await file.arrayBuffer()
      const buffer = Buffer.from(bytes)
      await writeFile(filepath, buffer)

      // Déterminer le type de fichier
      const fileType = file.type.startsWith('image/') ? 'image' : 
                       file.type.startsWith('video/') ? 'video' :
                       file.type.startsWith('audio/') ? 'audio' : 'document'

      // Créer l'entrée en base de données
      const media = await prisma.media.create({
        data: {
          name: file.name,
          url: `/uploads/${filename}`,
          type: fileType,
          mimeType: file.type,
          size: file.size,
        },
      })

      uploadedMedia.push(media)
    }

    return NextResponse.json({
      success: true,
      media: uploadedMedia,
    })
  } catch (error) {
    console.error('Upload error:', error)
    return NextResponse.json(
      { error: "Erreur lors de l'upload" },
      { status: 500 }
    )
  }
}

export async function DELETE(request: NextRequest) {
  try {
    // Vérifier l'authentification
    const session = await auth()
    if (!session?.user || session.user.role !== 'ADMIN') {
      return NextResponse.json({ error: 'Non autorisé' }, { status: 401 })
    }

    const { searchParams } = new URL(request.url)
    const id = searchParams.get('id')

    if (!id) {
      return NextResponse.json({ error: 'ID requis' }, { status: 400 })
    }

    const media = await prisma.media.findUnique({
      where: { id },
    })

    if (!media) {
      return NextResponse.json({ error: 'Média non trouvé' }, { status: 404 })
    }

    // Supprimer le fichier
    const filepath = join(process.cwd(), 'public', media.url)
    try {
      const { unlink } = await import('fs/promises')
      await unlink(filepath)
    } catch {
      // Le fichier n'existe peut-être plus
    }

    // Supprimer de la base de données
    await prisma.media.delete({
      where: { id },
    })

    return NextResponse.json({ success: true })
  } catch (error) {
    console.error('Delete error:', error)
    return NextResponse.json(
      { error: 'Erreur lors de la suppression' },
      { status: 500 }
    )
  }
}
