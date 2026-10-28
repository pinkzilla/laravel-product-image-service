<?php

namespace Portfolio\ProductImages\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DeleteGuardTest extends TestCase
{
    private function createFile(string $name): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        return $path;
    }

    #[Test]
    public function it_deletes_a_file_nobody_uses(): void
    {
        $path = $this->createFile('orphan.jpg');

        $this->assertTrue($this->service->deleteFileIfUnused('orphan.jpg'));
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function it_keeps_a_file_that_is_a_products_main_image(): void
    {
        $path = $this->createFile('main.jpg');
        $this->insertProduct('main.jpg', null);

        $this->assertFalse($this->service->deleteFileIfUnused('main.jpg'));
        $this->assertFileExists($path);
    }

    #[Test]
    public function it_keeps_a_file_that_is_listed_in_another_products_gallery(): void
    {
        // The paired product of the other branch shares the same file
        $path = $this->createFile('shared.jpg');
        $this->insertProduct(null, ['other.jpg', 'shared.jpg']);

        $this->assertFalse($this->service->deleteFileIfUnused('shared.jpg'));
        $this->assertFileExists($path);
    }

    #[Test]
    public function a_file_name_that_is_only_part_of_another_name_does_not_count_as_used(): void
    {
        $path = $this->createFile('cake.jpg');
        $this->insertProduct(null, ['big-cake.jpg']);

        $this->assertTrue($this->service->deleteFileIfUnused('cake.jpg'));
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function it_returns_false_when_the_file_does_not_exist(): void
    {
        $this->assertFalse($this->service->deleteFileIfUnused('missing.jpg'));
    }

    #[Test]
    #[DataProvider('unsafeNames')]
    public function it_refuses_unsafe_file_names(string $name): void
    {
        // A decoy outside the image folder that a path-traversal name could hit
        $outside = dirname($this->dir) . '/decoy-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'keep me');

        $this->assertFalse($this->service->deleteFileIfUnused($name));
        $this->assertFileExists($outside);
        @unlink($outside);
    }

    public static function unsafeNames(): array
    {
        return [
            'empty'             => [''],
            'hidden file'       => ['.htaccess'],
            'with spaces'       => ['my cake.jpg'],
            'with semicolon'    => ['a.jpg;rm'],
            'thai characters'   => ['เค้ก.jpg'],
        ];
    }

    #[Test]
    public function path_traversal_is_reduced_to_the_bare_file_name(): void
    {
        // basename('../x.jpg') === 'x.jpg', so it can only ever touch the image folder
        $inside = $this->createFile('x.jpg');
        $outside = dirname($this->dir) . '/x.jpg';
        file_put_contents($outside, 'keep me');

        $this->assertTrue($this->service->deleteFileIfUnused('../x.jpg'));
        $this->assertFileDoesNotExist($inside);
        $this->assertFileExists($outside);
        @unlink($outside);
    }

    #[Test]
    public function is_file_in_use_by_others_ignores_the_product_itself(): void
    {
        $a = $this->insertProduct('shared.jpg', null);
        $b = $this->insertProduct(null, ['shared.jpg']);

        $this->assertTrue($this->service->isFileInUseByOthers('shared.jpg', $a));
        $this->assertTrue($this->service->isFileInUseByOthers('shared.jpg', $b));

        $c = $this->insertProduct('alone.jpg', null);
        $this->assertFalse($this->service->isFileInUseByOthers('alone.jpg', $c));
    }
}
