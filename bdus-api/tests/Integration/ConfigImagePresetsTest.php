<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Config::save_app_properties() rejects invalid derived-image presets before
 * anything is persisted (so no config file is written by these tests).
 */
class ConfigImagePresetsTest extends BdusTestCase
{
    public function testInvalidPresetsAreRejectedWithACode(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'imagePresets' => [['name' => 'Bad Name', 'max_px' => 100]],
        ]);
        $res = $this->callController($ctrl, 'save_app_properties');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_image_presets', $res['code']);
        $this->assertArrayHasKey('detail', $res);
    }
}
