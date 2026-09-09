<?php

namespace App\Support;

/**
 * HOW the money arrived.
 *
 * A column, not a free-text field, because "cash" / "Cash" / "cash payment"
 * are the same fact and a reconciliation that groups by string gets three
 * answers. Stored as a string and cast here rather than as a database enum on
 * purpose: Lebanon gains payment rails faster than this schema should change,
 * and adding one must be a case here, not a migration.
 *
 * Whish and OMT are listed by name because they are how a large share of
 * Lebanese SMEs are actually paid. Neither is an integration — nothing in this
 * product talks to either service. These labels record what a human already
 * knows happened.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Whish = 'whish';
    case Omt = 'omt';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return __('app.payments.method_'.$this->value);
    }

    /**
     * Whether this method normally carries a reference worth recording — a
     * cheque number, an OMT transaction ID, a bank reference. Cash does not,
     * which is why `reference` is nullable rather than required.
     */
    public function expectsReference(): bool
    {
        return $this !== self::Cash;
    }

    /** value => label, for a select. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
