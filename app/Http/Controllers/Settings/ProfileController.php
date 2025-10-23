<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Show the API keys settings page.
     */
    public function editApiKeys(Request $request): Response
    {
        $apiKeys = $request->user()->api_keys ?? [];

        return Inertia::render('settings/api-keys', [
            'apiKeys' => [
                'openai' => $apiKeys['openai'] ?? null,
                'anthropic' => $apiKeys['anthropic'] ?? null,
            ],
        ]);
    }

    /**
     * Update the user's API keys.
     */
    public function updateApiKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openai_api_key' => 'nullable|string',
            'anthropic_api_key' => 'nullable|string',
        ]);

        $apiKeys = $request->user()->api_keys ?? [];

        if (!empty($validated['openai_api_key'])) {
            $apiKeys['openai'] = $validated['openai_api_key'];
        }

        if (!empty($validated['anthropic_api_key'])) {
            $apiKeys['anthropic'] = $validated['anthropic_api_key'];
        }

        $request->user()->update([
            'api_keys' => $apiKeys,
        ]);

        return to_route('api-keys.edit')->with('status', 'api-keys-updated');
    }
}
