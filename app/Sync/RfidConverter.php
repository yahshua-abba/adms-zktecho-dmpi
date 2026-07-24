<?php

namespace App\Sync;

/**
 * Produces the Card value to push to a device's USERINFO from the DMPI RFID.
 *
 * ZKTeco's push protocol accepts a four-byte RFID in the raw hexadecimal form
 * `Card=[AABBCCDD]`. DMPI stores those same card bytes with colon separators
 * (`AA:BB:CC:DD`), so this converter removes the separators and adds brackets.
 * Numeric card values remain strings because the protocol supports those too.
 */
class RfidConverter
{
    public static function toCard(?string $rfid): ?string
    {
        $rfid = trim((string) $rfid);

        if ($rfid === '') {
            return null;
        }

        if (preg_match('/^(?:[0-9a-f]{2}:){3}[0-9a-f]{2}$/i', $rfid)) {
            return '['.strtoupper(str_replace(':', '', $rfid)).']';
        }

        return $rfid;
    }
}
