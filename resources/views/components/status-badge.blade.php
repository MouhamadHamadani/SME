@props(['status' => 'filed'])

{{--
    Status badge — green / amber / red.

    Built here for the marketing site's compliance section, but the real
    consumer is the app: filing periods, NSSF declarations and VAT returns all
    render one of these. Keep the status keys (filed / due-soon / overdue)
    stable — the app will map its own enum onto them.

    Each pairing is AA-contrasting against its own ground:
      filed     #1B7A5B + white       5.3:1
      due-soon  #D9822B + charcoal    5.2:1
      overdue   #C0392B + white       5.4:1

    The dot is redundant with the colour on purpose — colour alone is not an
    accessible signal, so the label always states the status in words too.
--}}
@php
    $styles = [
        'filed' => 'bg-compliance-green text-white',
        'due-soon' => 'bg-status-due-soon text-warm-charcoal',
        'overdue' => 'bg-status-overdue text-white',
    ];

    $dots = [
        'filed' => 'bg-white',
        'due-soon' => 'bg-warm-charcoal',
        'overdue' => 'bg-white',
    ];

    $key = array_key_exists($status, $styles) ? $status : 'filed';
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ' . $styles[$key],
]) }}>
    <span aria-hidden="true" class="block size-1.5 rounded-full {{ $dots[$key] }}"></span>
    {{ $slot }}
</span>
