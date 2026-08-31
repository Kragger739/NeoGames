<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UnlockRequirement;

class UnlockRequirementController extends Controller
{
    /**
     * GET /api/unlock-requirements - the { key: required_level } map the
     * client uses to render locked modes / genres / the game-night button.
     */
    public function index()
    {
        return response()->json(UnlockRequirement::map());
    }
}
