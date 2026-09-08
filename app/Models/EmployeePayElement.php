<?php

namespace App\Models;

use App\Payroll\Eosi\PayElement;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bonus or overtime payment. Read by the EOSI accrual and nothing else.
 *
 * Deliberately NOT wired into the monthly payroll run — see the migration.
 */
class EmployeePayElement extends Model
{
    public const KINDS = ['bonus', 'overtime'];

    protected $fillable = [
        'employee_id',
        'kind',
        'amount_minor',
        'amount_currency',
        'paid_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'amount_currency' => Currency::class,
            'paid_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->amount_currency);
    }

    /** The pure value object the EOSI strategy works in. */
    public function toPayElement(): PayElement
    {
        return new PayElement($this->kind, $this->amount(), $this->paid_on);
    }
}
