<?php

namespace App\Services\CompanyCard;

use RuntimeException;

/**
 * A provider answer that does not satisfy `company-card-v1`. The message names
 * the failed rule only, never the offending content.
 */
class InvalidCompanyCard extends RuntimeException {}
