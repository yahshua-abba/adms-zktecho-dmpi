<?php

namespace Tests\Unit;

use App\Sync\LogType;
use Tests\TestCase;

class LogTypeTest extends TestCase
{
    public function test_dedicated_directions_are_fixed(): void
    {
        $this->assertSame('in', LogType::resolve('in', 0));
        $this->assertSame('in', LogType::resolve('in', 1));
        $this->assertSame('out', LogType::resolve('out', 0));
    }

    public function test_both_uses_punch_state_even_in_odd_out(): void
    {
        $this->assertSame('in', LogType::resolve('both', 0));
        $this->assertSame('out', LogType::resolve('both', 1));
        $this->assertSame('in', LogType::resolve('both', 2));
        $this->assertSame('out', LogType::resolve('both', 3));
    }

    public function test_unconfigured_direction_is_null(): void
    {
        $this->assertNull(LogType::resolve(null, 0));
    }
}
