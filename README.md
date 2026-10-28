# Product Image Service (Laravel)

A small, self-contained Laravel service that stores product photos safely. It is extracted from a
production system I maintain: an online cake shop (Laravel 12 / PHP 8.2 on shared hosting) with two
branches, used by real customers and shop staff every day.

## The problem

Staff uploaded product photos from phones, tablets and cameras, saved the product, and the photo
"disappeared". The causes were mixed:

| Cause | What this service does |
|---|---|
| Phone photos (HEIC, wrong EXIF orientation, huge files) that some browsers cannot show | Re-encodes every upload to a clean JPEG: auto-rotate, strip broken metadata, max 1600 px, quality 85 |
| Servers differ (Imagick missing, GD only) | Tries Imagick, then GD, then keeps the original file if it is a browser-safe format |
| A "successful" upload that wrote a broken or empty file | Every attempt is verified (`getimagesize`) before success is reported; broken files are removed |
| Deleting a photo removed a file that the paired product of the other branch still used | `deleteFileIfUnused()` checks the database first |
| "Sync paired product" overwrote the target's photos with the source's list | `mergeForSync()` merges both lists, de-duplicated, nothing lost |
| Odd file names arriving with a delete request | Only `[A-Za-z0-9._-]`, no leading dot, always reduced with `basename()` |

Errors are thrown as `RuntimeException` with plain messages that can be shown to shop staff directly.

## Usage

```php
use Portfolio\ProductImages\ProductImageService;

$images = new ProductImageService(public_path('img/cakes'));   // or bind it in a service provider

// Upload
try {
    $fileName = $images->store($request->file('photo'));       // e.g. "6f1c...e2.jpg"
    // save $fileName into products.image_url / products.images
} catch (\RuntimeException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
}

// Delete (only touches the disk when nothing else uses the file)
$images->deleteFileIfUnused($fileName);

// Sync a product with its pair in the other branch without losing photos
$merged = ProductImageService::mergeForSync($src->images, $src->image_url, $dst->images, $dst->image_url);
```

Expected table: `products(product_id, image_url NULL, images NULL /* JSON array of file names */)`.

## Run the tests

```bash
composer install
vendor/bin/phpunit --testdox
```

27 tests, using Orchestra Testbench and an in-memory SQLite database. They cover resizing, no
up-scaling, transparent PNG flattening, rejecting non-images (and leaving no file behind), upload
error messages, the delete guard (including path traversal and unsafe names) and every branch of
`mergeForSync()`. I also checked that the tests really fail when the delete guard is removed.

## Differences from the production version

- Namespace and constructor injection of the image folder (production uses a static `dir()` helper).
- Messages and comments are in English here (production shows Thai messages to staff).
- In production, the controller wraps upload/delete in a DB transaction with `lockForUpdate()` so two
  staff members editing the same product cannot overwrite each other. That part is not in this class.

## Known limitations (honest list)

- The Imagick path and GD's EXIF auto-rotation are not covered by automated tests (Imagick is not
  installed on my dev machine, and I did not craft an EXIF-rotated fixture yet). They still need a
  manual check with real phone/tablet photos on each server.
- `images LIKE '%"name"%'` on a JSON text column is fine for a few hundred products, but a separate
  `product_images` table would be the proper design if the catalogue grows.
- Deleting is "check, then unlink", which is not atomic. The caller should hold a lock (see above).
- HEIC needs Imagick built with HEIC support; without it the upload is rejected with a clear message.
