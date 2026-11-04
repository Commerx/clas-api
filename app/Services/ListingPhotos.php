<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Storing and removing a listing's photos.
 *
 * The old endpoint did this:
 *
 *     $image = UploadedFile::getInstanceByName('image');
 *     $imageName = Yii::$app->security->generateRandomString() . '.' . $image->getExtension();
 *     $image->saveAs($uploadPath . '/' . $imageName);
 *
 * `getExtension()` is `pathinfo($name, PATHINFO_EXTENSION)` on the *client's*
 * filename, and `$uploadPath` is under the document root. So the extension of
 * a file written into a web-served directory was whatever the caller chose,
 * before anything looked at the contents — and the thumbnail step that would
 * have failed on a non-image runs afterwards, so its failure does not remove
 * what was already saved. Verified against the running old API: a text file
 * posted as `probe.txt` was written to
 * `web/uploads/photos/<id>/<random>.txt` and served at that URL with HTTP 200.
 * On a host where PHP is handled across the document root, which is the
 * normal configuration, that is remote code execution for any caller who owns
 * or manages a listing.
 *
 * There was also no null check on the file, so a request with no `image` part
 * reached `$image->getExtension()` on null.
 *
 * Here: the upload must be a real image of an allowed type, the extension
 * comes from what the bytes actually are rather than from the name, the size
 * is capped, and nothing is written until all of that holds.
 */
class ListingPhotos
{
    /** GD's type constant for each extension this accepts. */
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Store an upload and return the names written.
     *
     * @return array{name: string, type: string, size: int}
     *
     * @throws RuntimeException when the upload is not a usable image
     */
    public function store(UploadedFile $file, int $landId): array
    {
        $size = (int) $file->getSize();

        if ($size <= 0 || $size > (int) config('clas.uploads.photo_max_bytes')) {
            throw new RuntimeException('That image is too large.');
        }

        // What the bytes are, not what the name claims. getimagesize returns
        // false for anything it cannot read as an image, which is the check
        // the old endpoint never made.
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! isset(self::IMAGE_TYPES[$info[2]])) {
            throw new RuntimeException('That file is not an image this API accepts.');
        }

        $extension = self::IMAGE_TYPES[$info[2]];

        if (! in_array($extension, (array) config('clas.uploads.photo_types'), true)) {
            throw new RuntimeException('That file is not an image this API accepts.');
        }

        $directory = $this->directory($landId);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException('The photo could not be stored.');
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;

        if (! $file->move($directory, $name)) {
            throw new RuntimeException('The photo could not be stored.');
        }

        $this->writeDerivedSizes($directory.'/'.$name, $directory, $name, $info[2]);

        return ['name' => $name, 'type' => $info['mime'], 'size' => $size];
    }

    /**
     * Remove a photo and the sizes derived from it.
     *
     * The old endpoint refused to delete the row when the original was
     * missing from disk, so a row whose file had already gone could not be
     * cleared through the API at all. Here the row is the thing being
     * removed and a missing file is not an error.
     */
    public function remove(string $photoName, int $landId): void
    {
        $directory = $this->directory($landId);

        foreach (array_merge([''], array_keys((array) config('clas.uploads.photo_sizes'))) as $prefix) {
            $path = $directory.'/'.$prefix.$photoName;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function directory(int $landId): string
    {
        return rtrim((string) config('clas.uploads.photos_path'), '/\\').'/'.$landId;
    }

    /**
     * The list and detail sizes the app expects beside each original.
     *
     * GD rather than a library, because this is the only image work in the
     * application. A failure here leaves the original in place and is not
     * reported: the app falls back to the original, which is what the old
     * behaviour amounted to whenever Imagine could not read the file.
     */
    private function writeDerivedSizes(string $source, string $directory, string $name, int $type): void
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => false,
        };

        if ($image === false) {
            return;
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        foreach ((array) config('clas.uploads.photo_sizes') as $prefix => [$width, $height]) {
            // Fit inside the box rather than stretch to it, which is what
            // Imagine's thumbnail() did, and never scale up.
            $scale = min($width / $sourceWidth, $height / $sourceHeight, 1);

            $scaled = @imagescale(
                $image,
                max(1, (int) round($sourceWidth * $scale)),
                max(1, (int) round($sourceHeight * $scale)),
            );

            if ($scaled === false) {
                continue;
            }

            $this->write($scaled, $directory.'/'.$prefix.$name, $type);
            imagedestroy($scaled);
        }

        imagedestroy($image);
    }

    /** Written in the original's format, since the name keeps its extension. */
    private function write(\GdImage $image, string $path, int $type): void
    {
        match ($type) {
            IMAGETYPE_JPEG => @imagejpeg($image, $path, 90),
            IMAGETYPE_PNG => @imagepng($image, $path),
            IMAGETYPE_GIF => @imagegif($image, $path),
            IMAGETYPE_WEBP => @imagewebp($image, $path, 90),
            default => null,
        };
    }
}
