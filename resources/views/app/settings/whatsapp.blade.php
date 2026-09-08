@php
    $connected = $tenant->hasWhatsApp();
    $live = config('whatsapp.driver') === 'cloud';
@endphp

<x-layouts.app :title="__('app.settings.whatsapp.heading')">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight text-trust-navy sm:text-3xl">
            {{ __('app.settings.whatsapp.heading') }}
        </h1>
        <p class="mt-3 max-w-prose leading-relaxed text-warm-charcoal/70">
            {{ __('app.settings.whatsapp.intro') }}
        </p>

        @if (session('status'))
            <x-card tone="light" accent="green" class="mt-6" role="status">
                <p class="font-medium text-warm-charcoal">{{ session('status') }}</p>
            </x-card>
        @endif

        @error('access_token')
            <x-card tone="light" accent="overdue" class="mt-6">
                <p class="font-medium text-warm-charcoal">{{ $message }}</p>
            </x-card>
        @enderror

        {{-- Say plainly that nothing is sent. Believing an invoice reached a
             customer when it never left the machine is the worst failure this
             feature has, and this screen is where someone forms that belief. --}}
        @unless ($live)
            <x-card tone="light" accent="due-soon" class="mt-6">
                <p class="font-semibold text-warm-charcoal">{{ __('app.settings.whatsapp.test_mode') }}</p>
                <p class="mt-2 text-sm leading-relaxed text-warm-charcoal/70">
                    {{ __('app.settings.whatsapp.test_mode_detail') }}
                </p>
            </x-card>
        @endunless

        {{-- Current state. --}}
        <x-card tone="light" :accent="$connected ? 'green' : null" class="mt-6">
            <h2 class="font-semibold text-trust-navy">{{ __('app.settings.whatsapp.status_heading') }}</h2>

            @if ($connected)
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.settings.whatsapp.number') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                            {{ $tenant->wa_phone_number ?: __('app.settings.whatsapp.number_unknown') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.settings.whatsapp.phone_number_id') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                            {{ $tenant->wa_phone_number_id }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-warm-charcoal/70">{{ __('app.settings.whatsapp.token') }}</dt>
                        {{-- Never rendered back, not even masked. --}}
                        <dd class="mt-0.5 font-semibold text-warm-charcoal">{{ __('app.settings.whatsapp.token_stored') }}</dd>
                    </div>
                    @if ($tenant->whatsappConnectedAt())
                        <div>
                            <dt class="text-sm text-warm-charcoal/70">{{ __('app.settings.whatsapp.connected_on') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-warm-charcoal" dir="ltr">
                                {{ \Illuminate\Support\Carbon::parse($tenant->whatsappConnectedAt())->format('Y-m-d H:i') }}
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    @if ($live)
                        <form method="POST" action="{{ route('app.settings.whatsapp.test') }}">
                            @csrf
                            <x-button type="submit" variant="secondary" size="md">
                                {{ __('app.settings.whatsapp.test') }}
                            </x-button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('app.settings.whatsapp.disconnect') }}">
                        @csrf
                        @method('DELETE')
                        <x-button
                            type="submit" variant="ghost" size="md"
                            onclick="return confirm(@js(__('app.settings.whatsapp.disconnect_confirm')))"
                        >{{ __('app.settings.whatsapp.disconnect') }}</x-button>
                    </form>
                </div>
            @else
                <p class="mt-2 text-warm-charcoal/70">{{ __('app.settings.whatsapp.not_connected') }}</p>
            @endif
        </x-card>

        {{-- Connect, or replace a rotated token. --}}
        <form method="POST" action="{{ route('app.settings.whatsapp.update') }}" class="mt-6" novalidate>
            @csrf

            <x-card tone="light" class="space-y-5">
                <h2 class="font-semibold text-trust-navy">
                    {{ $connected ? __('app.settings.whatsapp.replace_heading') : __('app.settings.whatsapp.connect_heading') }}
                </h2>

                <x-form.input
                    name="phone_number_id"
                    :label="__('app.settings.whatsapp.phone_number_id')"
                    :hint="__('app.settings.whatsapp.phone_number_id_hint')"
                    :value="$tenant->wa_phone_number_id"
                    inputmode="numeric" dir="ltr" required
                />

                <x-form.input
                    name="access_token"
                    type="password"
                    :label="__('app.settings.whatsapp.token')"
                    :hint="__('app.settings.whatsapp.token_hint')"
                    autocomplete="off" dir="ltr" required
                />

                <x-button type="submit" variant="cta" size="lg">
                    {{ $connected ? __('app.settings.whatsapp.replace') : __('app.settings.whatsapp.connect') }}
                </x-button>
            </x-card>
        </form>

        {{-- The real friction is finding these two values, so the steps are on
             the screen rather than in a manual nobody opens. --}}
        <x-card tone="muted" class="mt-6">
            <h2 class="font-semibold text-trust-navy">{{ __('app.settings.whatsapp.where_heading') }}</h2>

            <ol class="mt-4 space-y-3 text-sm leading-relaxed text-warm-charcoal/70">
                @foreach (['step_1', 'step_2', 'step_3', 'step_4'] as $index => $step)
                    <li class="flex gap-3">
                        <span
                            aria-hidden="true"
                            class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-trust-navy text-xs font-bold tabular-nums text-warm-offwhite"
                        >{{ $index + 1 }}</span>
                        <span>{{ __('app.settings.whatsapp.'.$step) }}</span>
                    </li>
                @endforeach
            </ol>

            {{-- The single most common blocker, so it is not buried in a step. --}}
            <p class="mt-5 border-s-4 border-status-due-soon ps-4 text-sm font-medium leading-relaxed text-warm-charcoal">
                {{ __('app.settings.whatsapp.app_conflict') }}
            </p>
        </x-card>
    </div>
</x-layouts.app>
