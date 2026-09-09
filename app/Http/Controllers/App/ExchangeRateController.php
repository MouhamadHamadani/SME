<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Support\Currency;
use App\Support\Money;
use App\Support\RateBasis;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The two national rates (read-only here) and the business's own booking rate.
 *
 * The split matters. `exchange_rates` holds national figures — the market
 * quote and the Ministry's prescribed rate — entered centrally by the operator
 * with `exchange:rate`, because one business must not be able to edit the rate
 * every other business files at. What a business genuinely owns is the rate it
 * chooses to BOOK at, which is what the form here writes.
 */
class ExchangeRateController extends Controller
{
    public function index(): View
    {
        return view('app.settings.exchange-rates', [
            'tenant' => tenant(),
            'today' => ExchangeRate::asOf(Carbon::today()),
            'rates' => ExchangeRate::query()->orderByDesc('rate_date')->limit(30)->get(),
        ]);
    }

    /** The business's own booking rate — not either national figure. */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-settings');

        $data = $request->validate([
            'booking_rate' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $tenant = tenant();

        $tenant->update([
            'settings' => array_merge($tenant->settings, [
                'exchange_rate' => (float) $data['booking_rate'],
            ]),
        ]);

        return redirect()
            ->route('app.settings.exchange-rates')
            ->with('status', __('app.rates.saved'));
    }

    /**
     * Revaluation: what the gap between the rates is actually worth.
     *
     * Two different gaps, deliberately shown apart because they mean different
     * things:
     *
     *   market vs Ministry — the books-versus-filing gap on today's balances.
     *   issue rate vs today — unrealized FX movement since each invoice was
     *     issued, which is the one an accountant calls a gain or loss.
     */
    public function revaluation(): View
    {
        $today = ExchangeRate::asOf(Carbon::today());

        /*
         * What is still OWED in USD, not what was invoiced in USD. Since
         * payments exist, an invoice half collected carries half the FX
         * exposure — revaluing it at its full total overstates the movement by
         * exactly what has already been banked. balance() nets credit notes
         * too, for the same reason.
         */
        $outstanding = Invoice::query()
            ->issued()
            ->where('currency', Currency::USD->value)
            ->with('customer')
            ->orderBy('issued_on')
            ->get()
            ->reject(fn (Invoice $invoice) => $invoice->balance()->isZero())
            ->values();

        $marketRate = $today?->rateFor(RateBasis::Market);
        $mofRate = $today?->rateFor(RateBasis::Mof);

        $rows = $outstanding->map(function (Invoice $invoice) use ($marketRate, $mofRate) {
            $total = $invoice->balance();

            return [
                'invoice' => $invoice,
                'total' => $total,
                // What it was worth in lira the day it was issued.
                'at_issue' => $invoice->lbp_per_usd
                    ? $total->convertTo(Currency::LBP, (int) $invoice->lbp_per_usd)
                    : null,
                'at_market' => $marketRate ? $total->convertTo(Currency::LBP, $marketRate) : null,
                'at_mof' => $mofRate ? $total->convertTo(Currency::LBP, $mofRate) : null,
            ];
        });

        return view('app.revaluation', [
            'today' => $today,
            'rows' => $rows,
            'marketRate' => $marketRate,
            'mofRate' => $mofRate,
            'totalUsd' => $outstanding->reduce(
                fn (Money $carry, Invoice $i) => $carry->plus($i->total()),
                Money::zero(Currency::USD),
            ),
            'history' => ExchangeRate::query()->orderByDesc('rate_date')->limit(14)->get(),
        ]);
    }
}
