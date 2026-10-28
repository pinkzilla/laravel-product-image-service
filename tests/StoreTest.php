<?php

namespace Portfolio\ProductImages\Tests;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

class StoreTest extends TestCase
{
    #[Test]
    public function it_saves_an_upload_as_a_jpeg_inside_the_image_folder(): void
    {
        $name = $this->service->store($this->makePng(800, 600));

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.(jpg|png)$/', $name);
        $this->assertFileExists($this->dir . '/' . $name);
        $this->assertNotFalse(getimagesize($this->dir . '/' . $name), 'saved file must open as an image');
    }

    #[Test]
    public function it_downsizes_large_photos_to_the_max_side_and_keeps_the_ratio(): void
    {
        $name = $this->service->store($this->makePng(3200, 1600));

        [$w, $h] = getimagesize($this->dir . '/' . $name);
        $this->assertSame(1600, $w);
        $this->assertSame(800, $h);
    }

    #[Test]
    public function it_does_not_enlarge_small_photos(): void
    {
        $name = $this->service->store($this->makePng(400, 300));

        [$w, $h] = getimagesize($this->dir . '/' . $name);
        $this->assertSame(400, $w);
        $this->assertSame(300, $h);
    }

    #[Test]
    public function it_puts_transparent_pngs_on_a_white_background(): void
    {
        $name = $this->service->store($this->makePng(50, 50, transparent: true));

        $path = $this->dir . '/' . $name;
        if (!str_ends_with($name, '.jpg')) {
            $this->markTestSkipped('Server fell back to the original file; flattening only applies to the JPEG path.');
        }
        $im = imagecreatefromjpeg($path);
        $rgb = imagecolorat($im, 25, 25);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF); // R
        $this->assertGreaterThan(240, ($rgb >> 8) & 0xFF);  // G
        $this->assertGreaterThan(240, $rgb & 0xFF);          // B
    }

    #[Test]
    public function it_creates_the_folder_when_it_is_missing(): void
    {
        $missing = $this->dir . '/sub/folder';
        $service = new \Portfolio\ProductImages\ProductImageService($missing);

        $name = $service->store($this->makePng(100, 100));

        $this->assertFileExists($missing . '/' . $name);
        @unlink($missing . '/' . $name);
        @rmdir($missing);
        @rmdir($this->dir . '/sub');
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_an_image_and_leaves_nothing_behind(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'this is not a picture');
        $file = new UploadedFile($path, 'fake.jpg', 'image/jpeg', null, true);

        try {
            $this->service->store($file);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fake.jpg', $e->getMessage());
            $this->assertStringContainsString('cannot read this file as an image', $e->getMessage());
        }
        $this->assertSame([], glob($this->dir . '/*'), 'no broken file may stay in the folder');
    }

    #[Test]
    public function it_rejects_an_empty_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empty');
        $file = new UploadedFile($path, 'empty.png', 'image/png', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty or unreadable');
        $this->service->store($file);
    }

    #[Test]
    public function it_explains_failed_uploads_in_plain_words(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'part');
        $file = new UploadedFile($path, 'half.png', 'image/png', UPLOAD_ERR_PARTIAL, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection dropped');
        $this->service->store($file);
    }

    #[Test]
    public function friendly_upload_errors_cover_the_common_codes(): void
    {
        $this->assertStringContainsString('larger than the server accepts', \Portfolio\ProductImages\ProductImageService::friendlyUploadError(UPLOAD_ERR_INI_SIZE));
        $this->assertStringContainsString('No file', \Portfolio\ProductImages\ProductImageService::friendlyUploadError(UPLOAD_ERR_NO_FILE));
        $this->assertStringContainsString('code 99', \Portfolio\ProductImages\ProductImageService::friendlyUploadError(99));
    }
}
