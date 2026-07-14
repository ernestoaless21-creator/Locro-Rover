<?php

namespace App\Http\Middleware;

use App\Models\Year;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            'currentYear' => fn () =>
                ($year = Year::where('is_active', true)->first())
                    ? $year->toPublicArray((bool) $user?->can('produccion.ver'))
                    : null,

            'years' => fn () =>
                Year::orderByDesc('year')
                    ->get(['id', 'year', 'label', 'is_active']),

            'permissions' => fn () =>
                $user
                    ? $user->getAllPermissions()->pluck('name')
                    : [],
        ];
    }
}