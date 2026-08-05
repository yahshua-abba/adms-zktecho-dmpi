<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * DMPI's roster pull came back with no employees at all.
 *
 * Same reasoning as EmptyDeviceInfoException: RosterSync now *removes* state on
 * the strength of what the roster says (it clears collisions that no longer
 * collide, and drops mappings for PINs that became contested), so an empty
 * payload would quietly undo real decisions. No live company has zero
 * employees, so this shape is a failed call, not a state worth acting on.
 */
class EmptyRosterException extends RuntimeException {}
