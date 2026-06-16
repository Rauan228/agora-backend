<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /** Форма входа. */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.suppliers.index');
        }

        return view('admin.auth.login');
    }

    /** Обработка входа. */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended(route('admin.suppliers.index'));
        }

        return back()
            ->withErrors(['email' => 'Неверный email или пароль.'])
            ->onlyInput('email');
    }

    /** Выход. */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
