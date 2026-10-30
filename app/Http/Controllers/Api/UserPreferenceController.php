<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateUserPreferenceRequest;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Reading never creates a row: the default lives in code so a new
        // account has a stable answer without a write.
        $preference = $request->user()->preferences()->first();

        return $this->respond($preference?->theme ?? UserPreference::THEME_SYSTEM);
    }

    public function update(UpdateUserPreferenceRequest $request): JsonResponse
    {
        $preference = UserPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['theme' => $request->validated()['theme']],
        );

        return $this->respond($preference->theme);
    }

    private function respond(string $theme): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['theme' => $theme],
        ]);
    }
}
