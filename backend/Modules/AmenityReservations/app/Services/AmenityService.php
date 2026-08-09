<?php

namespace Modules\AmenityReservations\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Modules\AmenityReservations\Models\Amenity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * AmenityService — gallery photo attachments for amenities. Mirrors
 * CommunityProjectService::attachDocument/detachDocument (Announcements'
 * AnnouncementService follows the same shape): validate type/size, cap the
 * collection, delegate to Spatie via App\Models\Media under the hood.
 */
class AmenityService
{
    public const MAX_GALLERY_IMAGES = 20;

    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function attachGalleryImage(Amenity $amenity, UploadedFile $file): Media
    {
        if (! in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('El archivo supera el límite de 10 MB.');
        }

        $attachedCount = $amenity->media()
            ->where('collection_name', 'gallery')
            ->count();

        if ($attachedCount >= self::MAX_GALLERY_IMAGES) {
            throw new \InvalidArgumentException('Máximo '.self::MAX_GALLERY_IMAGES.' fotos por amenidad.');
        }

        return $amenity->addMedia($file)
            ->toMediaCollection('gallery');
    }

    public function detachGalleryImage(Amenity $amenity, string $mediaId): void
    {
        $media = $amenity->media()
            ->where('id', $mediaId)
            ->where('collection_name', 'gallery')
            ->first();

        if ($media === null) {
            throw new ModelNotFoundException('Foto no encontrada.');
        }

        $media->delete();
    }
}
