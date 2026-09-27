<?php

namespace App\Http\Controllers;

use App\AppUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppUpdateController extends Controller
{
    public function show(AppUpdates $updates): JsonResponse
    {
        return response()->json($updates->state());
    }

    public function update(Request $request, string $action, AppUpdates $updates): JsonResponse
    {
        abort_unless(in_array($action, ['check', 'download', 'install'], true), 404);
        if ($action === 'install') {
            $request->validate(['confirmed' => ['accepted']]);
        }

        return response()->json($updates->perform($action));
    }
}
