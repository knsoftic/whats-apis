<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\Plan;
use App\Support\LandingContent;
use Illuminate\Contracts\View\View;
use Throwable;

/** Public home page — every text, image and video comes from Settings → Landing page. */
class LandingController extends Controller
{
    public function show(): View
    {
        $content = LandingContent::get();

        $plans = collect();

        if ($content['pricing']['enabled']) {
            try {
                $plans = Plan::active()->orderBy('sort_order')->orderBy('price')->get();
            } catch (Throwable) {
                // Plans table not migrated yet — hide pricing.
            }
        }

        return view('landing', [
            'c' => $content,
            'plans' => $plans,
            'registrationOpen' => RegisteredUserController::enabled(),
        ]);
    }
}
