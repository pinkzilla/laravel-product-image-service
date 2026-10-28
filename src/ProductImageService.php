<?php

namespace Portfolio\ProductImages;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stores product photos so that "uploaded photos never disappear", whatever device the staff used.
 *
 * - store(): cleans a new upload before saving it (auto-rotate by EXIF, strip broken metadata,
 *   downscale to at most 1600px, standard JPEG). Accepts photos from cameras / iPhone / iPad
 *   (HEIC too, if the server has Imagick). Order of attempts: Imagick -> GD -> keep the original file.
 *   Every attempt is verified (the written file must open as an image) before reporting success;
 *   on failure it throws a human-readable message and leaves no broken file behind.
 * - deleteFileIfUnused(): removes the file on disk only when no other product still references it
 *   (for example the paired product of another branch that shares the same file).
 * - mergeForSync(): merges the photos of two paired products without losing photos the target already has.
 *
 * Expected schema: products(product_id, image_url NULLABLE string, images NULLABLE JSON array of file names).
 */
class ProductImageService
{
    public const MAX_SIDE = 1600;
    public const JPEG_QUALITY = 85;

    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public static function friendlyUploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is larger than the server accepts.';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was incomplete (connection dropped). Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was selected.';
            default:
                return 'Upload failed (code ' . $code . ').';
        }
    }

    /**
     * @return string File name saved inside the image directory (store it in products.image_url / images).
     * @throws \RuntimeException With a message that can be shown to staff as-is.
     */
    public function store(UploadedFile $file): string
    {
        $label = $file->getClientOriginalName() ?: 'image';
        if (!$file->isValid()) {
            throw new \RuntimeException("{$label}: " . self::friendlyUploadError($file->getError()));
        }
        $dir = $this->directory;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('The product image folder does not exist on the server. Please contact the administrator.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('The product image folder is not writable. Please contact the administrator.');
        }
        $tmp = $file->getRealPath();
        if (!$tmp || !is_file($tmp) || filesize($tmp) === 0) {
            throw new \RuntimeException("{$label}: the file is empty or unreadable.");
        }

        $dest = $dir . '/' . Str::uuid() . '.jpg';

        // 1) Imagick (best at HEIC, orientation and colour profiles)
        if ($this->viaImagick($tmp, $dest) && $this->isValidImageFile($dest)) {
            return basename($dest);
        }
        @unlink($dest);

        // 2) GD
        if ($this->viaGd($tmp, $dest) && $this->isValidImageFile($dest)) {
            return basename($dest);
        }
        @unlink($dest);

        // 3) Last resort: keep the original file if it is a format every browser can display
        $info = @getimagesize($tmp);
        $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
        if ($ext) {
            $name = Str::uuid() . '.' . $ext;
            $target = $dir . '/' . $name;
            if (@copy($tmp, $target) && $this->isValidImageFile($target)) {
                return $name;
            }
            @unlink($target);
        }

        throw new \RuntimeException("{$label}: the system cannot read this file as an image. Save it as JPG/PNG and upload again.");
    }

    /**
     * Deletes the file only when no product references this file name (image_url or inside images).
     *
     * @return bool true = file deleted; false = not deleted (still used elsewhere, unsafe name, or no such file)
     */
    public function deleteFileIfUnused(string $filename): bool
    {
        $filename = basename($filename);
        // Plain file-name characters only: rejects odd names that arrive with a request
        if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename) || $filename[0] === '.') {
            return false;
        }
        $stillUsed = DB::table('products')
            ->where(function ($q) use ($filename) {
                $q->where('image_url', $filename)->orWhere('images', 'like', '%"' . $filename . '"%');
            })
            ->exists();
        if ($stillUsed) {
            return false;
        }
        $path = $this->directory . '/' . $filename;
        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * Merges the photos of a source product and its paired product ("sync pair") WITHOUT dropping photos
     * the target already has. (The first version overwrote the target with the source's full list, so a photo
     * staff had added on the target branch vanished from the product.)
     *
     * Order: source photos first, then the photos only the target has (de-duplicated).
     * image_url: the source's wins; falls back to the target's.
     *
     * @return array{images: ?string, image_url: ?string} `images` is JSON ready to store (null when there are no photos)
     */
    public static function mergeForSync(?string $srcImagesJson, ?string $srcUrl, ?string $dstImagesJson, ?string $dstUrl): array
    {
        $src = json_decode((string) $srcImagesJson, true);
        $dst = json_decode((string) $dstImagesJson, true);
        $src = is_array($src) ? $src : [];
        $dst = is_array($dst) ? $dst : [];
        // Legacy products that only have image_url (not yet listed in images) count as well
        if ($srcUrl && !in_array($srcUrl, $src, true)) $src[] = $srcUrl;
        if ($dstUrl && !in_array($dstUrl, $dst, true)) $dst[] = $dstUrl;
        $merged = array_values(array_unique(array_merge($src, $dst)));
        return [
            'images'    => $merged ? json_encode($merged) : null,
            'image_url' => $srcUrl ?: ($dstUrl ?: null),
        ];
    }

    public function isFileInUseByOthers(string $filename, int $exceptProductId): bool
    {
        $filename = basename($filename);
        return DB::table('products')
            ->where('product_id', '<>', $exceptProductId)
            ->where(function ($q) use ($filename) {
                $q->where('image_url', $filename)->orWhere('images', 'like', '%"' . $filename . '"%');
            })
            ->exists();
    }

    private function isValidImageFile(string $path): bool
    {
        return is_file($path) && filesize($path) > 0 && @getimagesize($path) !== false;
    }

    private function viaImagick(string $src, string $dest): bool
    {
        if (!class_exists('Imagick')) return false;
        try {
            $im = new \Imagick();
            $im->readImage($src . '[0]');                      // first frame only (multi-frame GIF/HEIC)
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();                             // rotate to the real orientation
            }
            $im->setImageBackgroundColor('white');
            if ($im->getImageAlphaChannel()) {
                $flat = new \Imagick();
                $flat->newImage($im->getImageWidth(), $im->getImageHeight(), 'white');
                $flat->compositeImage($im, \Imagick::COMPOSITE_OVER, 0, 0);
                $im->clear();
                $im = $flat;
            }
            if ($im->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            if (max($im->getImageWidth(), $im->getImageHeight()) > self::MAX_SIDE) {
                $im->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
            }
            $im->stripImage();                                  // drop EXIF / profiles that may be broken
            $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $im->setImageFormat('jpeg');
            $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $im->setImageCompressionQuality(self::JPEG_QUALITY);
            $ok = $im->writeImage($dest);
            $im->clear();
            return (bool) $ok;
        } catch (\Throwable $e) {
            Log::warning('ProductImageService Imagick failed: ' . $e->getMessage());
            return false;
        }
    }

    private function viaGd(string $src, string $dest): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) return false;
        try {
            $info = @getimagesize($src);
            if (!$info) return false;
            [$w, $h, $type] = $info;
            if ($w < 1 || $h < 1) return false;

            // Guard against running out of memory (decoded image ~ 5 bytes/pixel); if too big, skip to the original file
            $limit = self::memoryLimitBytes();
            if ($limit > 0 && ($w * $h * 5 + memory_get_usage()) > $limit * 0.8) return false;

            switch ($type) {
                case IMAGETYPE_JPEG: $im = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($src) : false; break;
                case IMAGETYPE_PNG:  $im = function_exists('imagecreatefrompng')  ? @imagecreatefrompng($src)  : false; break;
                case IMAGETYPE_GIF:  $im = function_exists('imagecreatefromgif')  ? @imagecreatefromgif($src)  : false; break;
                case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
                default: $im = false;
            }
            if (!$im) return false;

            // Rotate by EXIF orientation (JPEG only); invalid values such as 0 mean "do not rotate"
            if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($src);
                $o = (int) ($exif['Orientation'] ?? 1);
                $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($angle !== 0) {
                    $rot = @imagerotate($im, $angle, 0);
                    if ($rot) { $im = $rot; $w = imagesx($im); $h = imagesy($im); }
                }
            }

            $scale = min(1, self::MAX_SIDE / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // white background for transparent PNGs
            imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($out, $dest, self::JPEG_QUALITY);
            return (bool) $ok;
        } catch (\Throwable $e) {
            Log::warning('ProductImageService GD failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return 0;
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return $n;
    }
}
