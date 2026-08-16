<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * MUL-227 item 31 Fase 4 — CRUD de usuários secundários do dono da conta.
 * Sempre escopado por parent_user_id = auth()->id().
 */
class SubUserController extends Controller
{
    public const MENU_KEYS = [
        'dashboard', 'catalogo', 'kits', 'importadores', 'pedidos',
        'financeiro', 'notas_fiscais', 'integracoes', 'chamados', 'respostas',
        'videos_ia', 'ferramentas', 'meus_produtos', 'minha_conta',
    ];

    public function index(Request $request): JsonResponse
    {
        $users = SubUser::where('parent_user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users,
            'meta' => ['menu_keys' => self::MENU_KEYS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'email'       => ['required', 'email', 'max:191', 'unique:sub_users,email'],
            'password'    => ['required', 'string', 'min:6', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $sub = SubUser::create([
            'parent_user_id' => $request->user()->id,
            'name'           => $data['name'],
            'email'          => $data['email'],
            'password'       => Hash::make($data['password']),
            'permissions'    => $this->cleanPerms($data['permissions'] ?? null),
            'is_active'      => $data['is_active'] ?? true,
            'created_by'     => $request->user()->id,
        ]);

        return response()->json(['data' => $sub], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sub = SubUser::where('parent_user_id', $request->user()->id)->findOrFail($id);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:150'],
            'email'       => ['sometimes', 'email', 'max:191', Rule::unique('sub_users', 'email')->ignore($sub->id)],
            'password'    => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('password', $data) && ! empty($data['password'])) {
            $sub->password = Hash::make($data['password']);
        }
        if (array_key_exists('name', $data))        $sub->name = $data['name'];
        if (array_key_exists('email', $data))       $sub->email = $data['email'];
        if (array_key_exists('permissions', $data)) $sub->permissions = $this->cleanPerms($data['permissions']);
        if (array_key_exists('is_active', $data))   $sub->is_active = $data['is_active'];
        $sub->save();

        return response()->json(['data' => $sub->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $sub = SubUser::where('parent_user_id', $request->user()->id)->findOrFail($id);
        $sub->delete();

        return response()->json(['deleted' => true]);
    }

    private function cleanPerms(?array $raw): ?array
    {
        if (! $raw) return null;

        $out = [];
        foreach (self::MENU_KEYS as $k) {
            if (array_key_exists($k, $raw)) {
                $out[$k] = (bool) $raw[$k];
            }
        }
        return $out;
    }
}
