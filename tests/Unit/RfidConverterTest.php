<?php

namespace Tests\Unit;

use App\Sync\RfidConverter;
use Tests\TestCase;

class RfidConverterTest extends TestCase
{
    public function test_pushes_the_rfid_through_unchanged(): void
    {
        // No transformation — the card goes to the device exactly as DMPI stores it.
        $this->assertSame('1996052557', RfidConverter::toCard('1996052557'));
        $this->assertSame('55:2D:E3:D3', RfidConverter::toCard('55:2D:E3:D3'));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('1996052557', RfidConverter::toCard('  1996052557  '));
    }

    public function test_returns_null_for_empty(): void
    {
        $this->assertNull(RfidConverter::toCard(null));
        $this->assertNull(RfidConverter::toCard(''));
    }
}
