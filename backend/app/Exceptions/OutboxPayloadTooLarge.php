<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class OutboxPayloadTooLarge extends RuntimeException {}
