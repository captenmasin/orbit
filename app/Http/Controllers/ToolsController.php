<?php

namespace App\Http\Controllers;

use App\Actions\ProbeRuntimes;
use App\Rules\AbsoluteLocalPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Throwable;

class ToolsController extends Controller
{
    public function probe(Request $request, ProbeRuntimes $runtimes): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required', 'array:'.implode(',', ProbeRuntimes::TOOLS)],
            'paths.*' => ['nullable', 'string', 'max:4096', new AbsoluteLocalPath],
        ]);

        return response()->json(['runtimes' => $runtimes->handle('', globalPaths: $data['paths'])]);
    }

    public function pick(Request $request, Dialog $dialog): JsonResponse
    {
        $data = $request->validate(['tool' => ['required', Rule::in(ProbeRuntimes::TOOLS)]]);
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['path' => 'Enter the executable path in browser development.']);
        }
        try {
            $path = $dialog->files()->withHiddenFiles()->title('Select the '.$data['tool'].' executable')->button('Choose executable')->asSheet()->open();
        } catch (Throwable) {
            throw ValidationException::withMessages(['path' => 'The file picker could not open. Try again.']);
        }

        return response()->json(['path' => $path]);
    }
}
