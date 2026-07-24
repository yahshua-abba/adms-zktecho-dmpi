<?php

namespace Tests\Unit;

use App\Sync\RfidConverter;
use Tests\TestCase;

class RfidConverterTest extends TestCase
{
    public function test_formats_four_byte_hex_rfid_for_zkteco(): void
    {
        $this->assertSame('[3D349C43]', RfidConverter::toCard('3D:34:9C:43'));
        $this->assertSame('[552DE3D3]', RfidConverter::toCard('55:2D:E3:D3'));
        $this->assertSame('[3D349C43]', RfidConverter::toCard('3d:34:9c:43'));
    }

    public function test_keeps_numeric_rfid_as_a_card_number_string(): void
    {
        $this->assertSame('1996052557', RfidConverter::toCard('1996052557'));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('[3D349C43]', RfidConverter::toCard('  3D:34:9C:43  '));
    }

    public function test_returns_null_for_empty(): void
    {
        $this->assertNull(RfidConverter::toCard(null));
        $this->assertNull(RfidConverter::toCard(''));
    }
}
