<?php

use Illuminate\Support\Facades\Route;

Route::get('/debug-mail', function () {
    $mailers = config('mail.mailers');
    
    return response()->json([
        'default_mailer' => config('mail.default'),
        'sendgrid_api_config' => $mailers['sendgrid_api'] ?? 'NOT FOUND',
        'sendgrid_key' => config('services.sendgrid.key') ? 'SET' : 'NOT SET',
        'from_address' => config('mail.from.address'),
        'from_name' => config('mail.from.name'),
        'mail_driver_exists' => class_exists('\Sichikawa\LaravelSendgridDriver\SendgridTransportServiceProvider'),
    ]);
});
