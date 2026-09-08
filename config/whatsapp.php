<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | "log" writes the message it would have sent and returns a fake id. It is
    | the DEFAULT deliberately: an unconfigured install must never accidentally
    | message a real customer, and every developer machine is unconfigured.
    |
    | "cloud" is Meta's WhatsApp Cloud API. Switch to it only once the number
    | is verified and the templates are approved.
    |
    */
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    /*
    | Graph API version. Meta deprecates versions on a schedule, so this is
    | pinned rather than floating — an unpinned version breaks silently the
    | day it is retired.
    */
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Meta calls ONE url for the whole app, not one per business, so the
    | webhook lives on the central domain and routes to a tenant by the
    | phone_number_id in the payload.
    |
    | `verify_token` is echoed back during setup. `app_secret` verifies the
    | HMAC on every delivery callback — without it any caller could post fake
    | "delivered" statuses.
    |
    */
    'webhook' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Template
    |--------------------------------------------------------------------------
    |
    | A business-initiated message — which an invoice notification is — must
    | use a template Meta has APPROVED. Free-form text is only allowed inside
    | the 24-hour window after the customer messages you, which does not apply
    | here. Sending free text outside that window is rejected by the API.
    |
    | The template itself is created and approved in Meta's Business Manager;
    | this is only its name and the language it was approved in.
    |
    */
    'invoice_template' => [
        'name' => env('WHATSAPP_INVOICE_TEMPLATE', 'invoice_notification'),
        'language' => env('WHATSAPP_INVOICE_TEMPLATE_LANGUAGE', 'ar'),
    ],

];
