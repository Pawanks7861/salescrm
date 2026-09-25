<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Company logo / favicon upload and removal (route-gated by settings.manage). */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    public function store(Request $request, string $type): RedirectResponse
    {
        $request->validate(['file' => BrandingService::rules($type)], [
            'file.mimetypes' => $type === 'logo' ? 'The logo must be a PNG, JPG or WEBP image.' : 'The favicon must be a PNG, ICO or WEBP image.',
            'file.max' => 'The file may not be larger than '.(BrandingService::TYPES[$type]['max_kb'] >= 1024 ? (BrandingService::TYPES[$type]['max_kb'] / 1024).' MB' : BrandingService::TYPES[$type]['max_kb'].' KB').'.',
        ]);

        $this->branding->store($type, $request->file('file'), $request->user());

        return back()->with('success', ($type === 'logo' ? 'Company logo' : 'Favicon').' updated.');
    }

    public function destroy(Request $request, string $type): RedirectResponse
    {
        $this->branding->remove($type, $request->user());

        return back()->with('success', ($type === 'logo' ? 'Company logo' : 'Favicon').' removed.');
    }
}
