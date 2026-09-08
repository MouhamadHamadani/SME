<?php

namespace App\Payroll\Eosi;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * One bonus or overtime payment, on a date.
 *
 * The date is the whole point: §3.2 includes these in the indemnity base only
 * where they were paid consistently for three years or more, and "consistently"
 * is a question about dates.
 */
final readonly class PayElement
{
    public function __construct(
        public string $kind,
        public Money $amount,
        public Carbon $paidOn,
    ) {}
}
