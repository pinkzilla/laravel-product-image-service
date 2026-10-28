<?php

namespace Portfolio\ProductImages\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Portfolio\ProductImages\ProductImageService;

/** Pure function: no Laravel needed. */
class MergeForSyncTest extends TestCase
{
    #[Test]
    public function photos_only_the_target_has_are_kept(): void
    {
        $r = ProductImageService::mergeForSync('["a.jpg","b.jpg"]', 'a.jpg', '["c.jpg"]', 'c.jpg');

        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], json_decode($r['images'], true));
        $this->assertSame('a.jpg', $r['image_url'], "the source's main image wins");
    }

    #[Test]
    public function duplicates_are_removed_and_source_order_comes_first(): void
    {
        $r = ProductImageService::mergeForSync('["a.jpg","b.jpg"]', 'a.jpg', '["b.jpg","a.jpg","c.jpg"]', 'b.jpg');

        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], json_decode($r['images'], true));
    }

    #[Test]
    public function a_legacy_product_with_only_image_url_is_counted(): void
    {
        $r = ProductImageService::mergeForSync(null, 'old.jpg', '["new.jpg"]', null);

        $this->assertSame(['old.jpg', 'new.jpg'], json_decode($r['images'], true));
        $this->assertSame('old.jpg', $r['image_url']);
    }

    #[Test]
    public function the_main_image_falls_back_to_the_target_when_the_source_has_none(): void
    {
        $r = ProductImageService::mergeForSync(null, null, '["t.jpg"]', 't.jpg');

        $this->assertSame('t.jpg', $r['image_url']);
        $this->assertSame(['t.jpg'], json_decode($r['images'], true));
    }

    #[Test]
    public function no_photos_anywhere_gives_nulls_not_an_empty_array(): void
    {
        $r = ProductImageService::mergeForSync(null, null, null, null);

        $this->assertNull($r['images']);
        $this->assertNull($r['image_url']);
    }

    #[Test]
    public function broken_json_is_treated_as_an_empty_list(): void
    {
        $r = ProductImageService::mergeForSync('{not json', null, '["ok.jpg"]', null);

        $this->assertSame(['ok.jpg'], json_decode($r['images'], true));
    }
}
