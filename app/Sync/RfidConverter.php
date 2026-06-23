<?php

namespace App\Sync;

/**
 * Produces the Card value to push to a device's USERINFO from the DMPI RFID.
 *
 * Per requirement, the RFID is pushed EXACTLY as stored in DMPI — no hex/decimal
 * transformation. (The device is expected to read the card in the same format
 * DMPI holds it.) This is kept as a single seam so any future per-device
 * formatting has one home.
 */
class RfidConverter
{
    public static function toCard(?string $rfid): ?string
    {
        $rfid = trim((string) $rfid);

        return $rfid === '' ? null : $rfid;
    }
}
