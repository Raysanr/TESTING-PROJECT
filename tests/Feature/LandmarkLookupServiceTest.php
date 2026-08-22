<?php

namespace Tests\Feature;

use App\Models\Landmark;
use App\Services\LandmarkLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandmarkLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_nearest_landmark_within_range(): void
    {
        // ~15m away
        Landmark::create(['name' => 'Near Pharmacy', 'lat' => 14.6571, 'lon' => 121.0328, 'poi_type' => 'pharmacy']);
        // ~1.1km away — should not be selected
        Landmark::create(['name' => 'Far Church', 'lat' => 14.667, 'lon' => 121.0327, 'poi_type' => 'place_of_worship']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNotNull($result);
        $this->assertSame('Near Pharmacy', $result['name']);
        $this->assertSame('pharmacy', $result['poi_type']);
        $this->assertLessThan(100.0, $result['distance']);
    }

    public function test_returns_null_when_nothing_is_within_range(): void
    {
        // ~1.1km away, outside the 100m radius
        Landmark::create(['name' => 'Far Church', 'lat' => 14.667, 'lon' => 121.0327, 'poi_type' => 'place_of_worship']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_landmarks_exist(): void
    {
        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNull($result);
    }

    public function test_picks_the_closer_of_two_landmarks_both_within_range(): void
    {
        // ~15m away
        Landmark::create(['name' => 'Closer', 'lat' => 14.6571, 'lon' => 121.0328, 'poi_type' => 'pharmacy']);
        // ~55m away, still within 100m
        Landmark::create(['name' => 'Farther', 'lat' => 14.6575, 'lon' => 121.0327, 'poi_type' => 'fast_food']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertSame('Closer', $result['name']);
    }

    public function test_excludes_landmarks_within_bounding_box_but_beyond_100m_radius(): void
    {
        // ~130m away — within the bounding box (±167m) but beyond MAX_DISTANCE_METERS (100m)
        Landmark::create(['name' => 'Just Beyond Range', 'lat' => 14.6583, 'lon' => 121.0327, 'poi_type' => 'restaurant']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        // Should exclude it despite being inside the bounding box
        $this->assertNull($result);
    }
}
