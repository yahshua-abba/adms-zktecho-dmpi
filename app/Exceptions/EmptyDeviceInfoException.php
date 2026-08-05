<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * DMPI's device-info pull came back completely empty.
 *
 * Treated as a failure rather than a valid state: DeviceInfoSync replaces the
 * whole assignment table on every run, so acting on an empty payload deletes
 * every assignment and leaves EnrollmentReconciler queueing a delete for every
 * enrolled user on every linked device.
 */
class EmptyDeviceInfoException extends RuntimeException {}
