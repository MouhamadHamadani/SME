{{-- No registration route: the founder provisions each business with
     `php artisan tenant:create` during early access. --}}
<x-layouts.app :title="__('app.auth.title')" :chrome="false">
    <div class="mx-auto max-w-md py-8">
        <div class="flex justify-center">
            <x-wordmark />
        </div>

        <x-card tone="light" accent="gold" class="mt-8">
            <h1 class="text-xl font-bold text-trust-navy sm:text-2xl">
                {{ __('app.auth.heading', ['business' => tenant('name')]) }}
            </h1>

            <form method="POST" action="{{ route('app.login.attempt') }}" class="mt-6 space-y-5" novalidate>
                @csrf

                <x-form.input
                    name="email"
                    type="email"
                    :label="__('app.auth.email')"
                    autocomplete="username"
                    required
                    autofocus
                />

                {{-- Not x-form.input: that component repopulates from old(), and
                     a password must never be echoed back into the response. --}}
                <div class="space-y-1.5">
                    <label for="password" class="block text-sm font-semibold text-warm-charcoal">
                        {{ __('app.auth.password') }}
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                        @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif
                        class="w-full rounded-lg border-2 bg-white px-4 py-3 text-base text-warm-charcoal transition-colors focus:outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold {{ $errors->has('password') ? 'border-status-overdue' : 'border-warm-charcoal/20 focus:border-trust-navy' }}"
                    >
                    @error('password')
                        <p id="password-error" class="text-sm font-medium text-status-overdue">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2.5 text-sm text-warm-charcoal/80">
                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                        class="size-4 rounded border-2 border-warm-charcoal/25 text-trust-navy focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-warm-gold"
                    >
                    {{ __('app.auth.remember') }}
                </label>

                <x-button type="submit" variant="primary" size="lg" class="w-full">
                    {{ __('app.auth.submit') }}
                </x-button>
            </form>
        </x-card>

        <p class="mt-6 text-center text-sm text-warm-charcoal/70">
            {{ __('app.auth.no_account') }}
        </p>
    </div>
</x-layouts.app>
