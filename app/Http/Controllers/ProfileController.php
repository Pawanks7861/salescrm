<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users may only edit their own name and phone. Email, role, team and account
 * status are managed by administrators; self-deletion is not allowed.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'profile' => $request->user()->only('name', 'email', 'phone', 'employee_code', 'designation'),
        ]);
    }

    public function update(ProfileUpdateRequest $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        [$old, $new] = $audit->dirtyDiff($user);
        $user->save();

        if ($new !== []) {
            $audit->log(AuditAction::UserUpdated, 'profile', $user, "{$user->name} updated their profile", $old, $new);
        }

        return back()->with('success', 'Profile updated.');
    }
}
