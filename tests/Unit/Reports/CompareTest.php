<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Support\Compare;
use PHPUnit\Framework\TestCase;

class CompareTest extends TestCase
{
    public function test_increase_and_decrease(): void
    {
        $up = Compare::change(110, 100);
        $this->assertSame(10.0, $up['percent']);
        $this->assertSame(10.0, $up['difference']);
        $this->assertSame('up', $up['direction']);
        $this->assertTrue($up['improved']);

        $down = Compare::change(75, 100);
        $this->assertSame(-25.0, $down['percent']);
        $this->assertSame('down', $down['direction']);
        $this->assertFalse($down['improved']);
    }

    public function test_a_change_that_rounds_to_zero_is_flat(): void
    {
        $flat = Compare::change(1000, 1000.4);

        $this->assertSame(0.0, $flat['percent']);
        $this->assertSame('flat', $flat['direction']);
        $this->assertNull($flat['improved']);
        $this->assertSame('flat', Compare::change(5, 5)['direction']);
    }

    public function test_zero_previous_with_current_traffic_is_new_and_has_no_percent(): void
    {
        $new = Compare::change(5, 0);

        $this->assertNull($new['percent']);
        $this->assertSame('new', $new['direction']);
        $this->assertSame(5.0, $new['difference']);
        $this->assertNull($new['improved']);
    }

    public function test_zero_on_both_sides_is_none(): void
    {
        $this->assertSame('none', Compare::change(0, 0)['direction']);
        $this->assertNull(Compare::change(0, 0)['percent']);
    }

    public function test_missing_previous_is_new_when_there_is_current_traffic_and_none_otherwise(): void
    {
        $this->assertSame('new', Compare::change(7, null)['direction']);
        $this->assertNull(Compare::change(7, null)['percent']);
        $this->assertNull(Compare::change(7, null)['difference']);
        $this->assertSame('none', Compare::change(0, null)['direction']);
    }

    public function test_missing_current_is_none(): void
    {
        $none = Compare::change(null, 50);

        $this->assertSame('none', $none['direction']);
        $this->assertNull($none['percent']);
        $this->assertNull($none['difference']);
        $this->assertSame('none', Compare::change(null, null)['direction']);
    }

    public function test_dropping_to_zero_is_a_100_percent_decrease(): void
    {
        $this->assertSame(-100.0, Compare::change(0, 40)['percent']);
        $this->assertSame('down', Compare::change(0, 40)['direction']);
    }

    public function test_lower_is_better_inverts_improved_but_not_direction(): void
    {
        $better = Compare::change(8.0, 10.0, lowerIsBetter: true);
        $this->assertSame('down', $better['direction']);
        $this->assertTrue($better['improved']);

        $worse = Compare::change(12.0, 10.0, lowerIsBetter: true);
        $this->assertSame('up', $worse['direction']);
        $this->assertFalse($worse['improved']);
    }

    public function test_points_for_a_share_change(): void
    {
        $this->assertSame(['points' => -2.5, 'direction' => 'down'], Compare::points(30.0, 32.5));
        $this->assertSame(['points' => 0.0, 'direction' => 'flat'], Compare::points(30.0, 30.0));
        $this->assertSame(['points' => null, 'direction' => 'none'], Compare::points(30.0, null));
    }

    public function test_metric_includes_year_ago_only_when_given(): void
    {
        $weekly = Compare::metric(['current' => 10, 'previous' => 5]);
        $this->assertArrayNotHasKey('yearAgo', $weekly);
        $this->assertArrayNotHasKey('yearAgoChange', $weekly);
        $this->assertSame(100.0, $weekly['change']['percent']);

        $monthly = Compare::metric(['current' => 10, 'previous' => 5, 'yearAgo' => 0]);
        $this->assertSame(0, $monthly['yearAgo']);
        $this->assertSame('new', $monthly['yearAgoChange']['direction']);
    }
}
