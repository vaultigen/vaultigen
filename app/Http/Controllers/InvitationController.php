<?php

namespace App\Http\Controllers;

use App\Services\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController
{
    public function show(string $token): View
    {
        return view('invitation', ['token' => $token]);
    }

    public function store(Request $request, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'], 'email' => ['required', 'string'],
            'name' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed'], 'policy' => ['accepted'],
        ]);
        $user = $invitations->accept($data['token'], $data['email'], $data['name'], $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('filament.app.auth.multi-factor-authentication.set-up-required');
    }
}
