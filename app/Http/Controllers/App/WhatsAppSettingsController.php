<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\WhatsApp\Exceptions\WhatsAppException;
use App\WhatsApp\Senders\CloudApiSender;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Connect this business's own WhatsApp number, from the browser.
 *
 * There is a `whatsapp:connect` artisan command as well, but it needs shell
 * access to the server — fine for the operator, useless for a customer who
 * signed up an hour ago. This is the same operation as a screen the owner can
 * reach themselves.
 *
 * The token is never rendered back. It goes in write-only and is stored
 * encrypted; the screen reports whether it WORKS rather than what it is.
 */
class WhatsAppSettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('manage-settings');

        return view('app.settings.whatsapp', ['tenant' => tenant()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-settings');

        $data = $request->validate([
            // Meta's phone number id is numeric. Catching a pasted "+961 3…"
            // here is worth more than a rejected send an hour later.
            'phone_number_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $displayNumber = null;

        if ($this->isLive()) {
            try {
                $verified = (new CloudApiSender($data['phone_number_id'], $data['access_token']))->verify();
            } catch (WhatsAppException $e) {
                // Meta's own wording, verbatim. Ours would be a guess about
                // which of a dozen setup steps is missing.
                return back()->withErrors(['access_token' => $e->getMessage()]);
            }

            $displayNumber = $verified['display_phone_number'];
        }

        tenant()->connectWhatsApp($data['phone_number_id'], $data['access_token'], $displayNumber);

        return redirect()
            ->route('app.settings.whatsapp')
            ->with('status', __('app.settings.whatsapp.saved'));
    }

    /**
     * Re-check credentials that are already stored.
     *
     * Tokens get revoked and numbers get moved without anyone telling us. The
     * alternative to a button is finding out from a customer.
     */
    public function test(): RedirectResponse
    {
        $this->authorize('manage-settings');

        $credentials = tenant()->whatsappCredentials();

        if ($credentials === null) {
            return back()->withErrors(['access_token' => __('app.settings.whatsapp.not_connected')]);
        }

        if (! $this->isLive()) {
            return back()->withErrors(['access_token' => __('app.settings.whatsapp.cannot_test_in_log_mode')]);
        }

        try {
            $verified = (new CloudApiSender($credentials['phone_number_id'], $credentials['access_token']))->verify();
        } catch (WhatsAppException $e) {
            return back()->withErrors(['access_token' => $e->getMessage()]);
        }

        // Meta is the authority on what the number displays as; refresh it.
        tenant()->update(['wa_phone_number' => $verified['display_phone_number']]);

        return back()->with('status', __('app.settings.whatsapp.tested_ok', [
            'number' => $verified['display_phone_number'],
            'name' => $verified['verified_name'] !== '' ? $verified['verified_name'] : tenant('name'),
        ]));
    }

    public function destroy(): RedirectResponse
    {
        $this->authorize('manage-settings');

        tenant()->disconnectWhatsApp();

        return redirect()
            ->route('app.settings.whatsapp')
            ->with('status', __('app.settings.whatsapp.disconnected'));
    }

    /** Anything other than an explicit "cloud" sends nothing, so verifies nothing. */
    private function isLive(): bool
    {
        return config('whatsapp.driver') === 'cloud';
    }
}
