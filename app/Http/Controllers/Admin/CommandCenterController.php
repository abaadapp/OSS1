<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\CommandCenter;
use App\Support\Demo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مركزُ العمليات — الشاشة.
 *
 * لا استعلامَ هنا: كلُّ القراءة في `App\Support\CommandCenter`، والمتحكّم
 * يقول من يسأل (شركتُه، وهل يرى المال) ويردّ الشاشة.
 */
class CommandCenterController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        $bid = (int) ($user->business_id ?? Demo::bid());

        return Inertia::render('Admin/Command/Index', [
            'center' => CommandCenter::for($bid, $user),
        ]);
    }
}
