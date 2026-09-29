<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Native\Desktop\Facades\Notification;

class DebugController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Debug');
    }

    public function notification(): Response
    {
        abort_unless(config('nativephp-internal.running'), 422, 'Open Orbit desktop to test native notifications.');

        $notification = Notification::title('Orbit debug notification')
            ->message('This is a test notification from the Debug page.')
            ->show();

        abort_if($notification->reference === null, 502, 'The native notification could not be sent.');

        return response()->noContent();
    }
}
