<?php

namespace App\Services\Screener\Ai;

use RuntimeException;

/**
 * A provider answer that does not satisfy `ScreenerQuerySchema`. The message
 * names only the failed rule, never the answer or the user's text.
 */
class InvalidScreenerQuery extends RuntimeException {}
