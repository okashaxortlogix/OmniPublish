<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:8',
            'workspaceName' => 'nullable|string|min:2|max:50',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
        ]);

        $workspaceName = $validated['workspaceName'] ?? ($validated['name'] . "'s Studio");
        $workspace = Workspace::create([
            'name' => $workspaceName,
            'slug' => Str::slug($workspaceName) . '-' . substr(md5(uniqid()), 0, 6),
            'owner_id' => $user->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'OWNER',
        ]);

        AuditEvent::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'USER_REGISTERED',
            'resource_type' => 'User',
            'resource_id' => (string) $user->id,
        ]);

        // Generate token
        $token = 'omni_tok_' . bin2hex(random_bytes(32));

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'currentWorkspace' => $workspace,
                'token' => $token,
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials.']
            ]);
        }

        $workspace = $user->workspaces()->first() ?? $user->ownedWorkspaces()->first();

        $token = 'omni_tok_' . bin2hex(random_bytes(32));

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'currentWorkspace' => $workspace,
                'token' => $token,
            ]
        ]);
    }

    public function me(Request $request)
    {
        // For development / MVP header or session
        $user = User::first();
        $workspace = $user?->workspaces()->first() ?? $user?->ownedWorkspaces()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'currentWorkspace' => $workspace,
            ]
        ]);
    }

    public function logout()
    {
        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.'
        ]);
    }
}
