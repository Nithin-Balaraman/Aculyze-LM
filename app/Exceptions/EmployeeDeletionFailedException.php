<?php

namespace App\Exceptions;

use Exception;

/**
 * A friendly, user-facing failure for the guided employee-deletion flow
 * (UX Fixes Batch Issue 5). Thrown for validation failures (no/invalid
 * replacement chosen) and to wrap any unexpected failure encountered
 * mid-transaction, so App\Services\EmployeeDeletionService never lets a raw
 * exception reach the UI — the transaction is always rolled back by the
 * caller before this is thrown.
 */
class EmployeeDeletionFailedException extends Exception {}
