<?php

namespace App\Http\Controllers;

use App\Services\BrandingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public brand assets (the login page needs them before authentication).
 * Only the two configured files can be served; the storage path is never
 * exposed and no user input reaches the filesystem.
 */
class BrandingController extends Controller
{
    public function show(Request $request, BrandingService $branding, string $type): Response
    {
        return $branding->response($type, $request->filled('v'));
    }
}
