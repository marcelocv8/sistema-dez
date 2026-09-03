<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): CurrentUserResource|JsonResponse
    {
        $user = User::where('email', mb_strtolower($request->string('email')->toString()))->first();

        if ($user === null || ! $user->active || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return new JsonResponse([
                'message' => 'As credenciais informadas são inválidas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return new CurrentUserResource($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->noContent();
    }

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }
}
