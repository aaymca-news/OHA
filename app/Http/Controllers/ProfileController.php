<?php

namespace App\Http\Controllers;

use App\Actions\Users\UpdateOwnProfile;
use App\Actions\Users\UpdateProfilePhoto;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A person's own profile: their photo, name and job title. (Their password and
 * sign-in sessions are on the Security page.)
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()->load('assignedMovements:id,name', 'movement')]);
    }

    public function update(Request $request, UpdateOwnProfile $action): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'title' => ['nullable', 'string', 'max:80'],
        ]);

        $action->handle($request->user(), $data['name'], $data['title'] ?? null);

        return back()->with('status', 'Your details are saved.');
    }

    /** Adds or replaces their own profile photo. */
    public function updatePhoto(Request $request, UpdateProfilePhoto $action): RedirectResponse
    {
        $request->validate(
            ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64']],
            [
                'photo.required' => 'Choose a photo to upload.',
                'photo.mimes' => 'The photo must be a JPG, PNG or WebP image.',
                'photo.max' => 'The photo must be smaller than 5 MB.',
                'photo.dimensions' => 'The photo is too small: use one at least 64 pixels wide and high.',
            ],
        );

        $action->handle($request->user(), (string) $request->file('photo')->getRealPath());

        return back()->with('status', 'Your profile photo is saved. It now shows wherever your name appears.');
    }

    public function removePhoto(Request $request, UpdateProfilePhoto $action): RedirectResponse
    {
        $action->remove($request->user());

        return back()->with('status', 'Your profile photo is removed. Your initials show instead.');
    }

    /** A person's profile photo, for anyone signed in (it appears beside their name). */
    public function photo(User $user): StreamedResponse
    {
        abort_if($user->avatar_path === null, 404);

        return Storage::disk((string) $user->avatar_disk)->response($user->avatar_path, 'photo.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
