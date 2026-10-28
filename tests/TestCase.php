<?php

namespace Portfolio\ProductImages\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Portfolio\ProductImages\ProductImageService;

abstract class TestCase extends Orchestra
{
    protected string $dir;
    protected ProductImageService $service;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function ($t) {
            $t->increments('product_id');
            $t->string('image_url')->nullable();
            $t->text('images')->nullable();
        });

        // A fresh, empty image folder for every test
        $this->dir = sys_get_temp_dir() . '/product-image-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        $this->service = new ProductImageService($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Creates a real PNG on disk and wraps it as a "just uploaded" file. */
    protected function makePng(int $w, int $h, bool $transparent = false, string $name = 'photo.png'): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        if ($transparent) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // fully transparent
        } else {
            imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        }
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($im, $path);
        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    protected function insertProduct(?string $imageUrl, ?array $images): int
    {
        return \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'image_url' => $imageUrl,
            'images' => $images === null ? null : json_encode($images),
        ]);
    }
}
