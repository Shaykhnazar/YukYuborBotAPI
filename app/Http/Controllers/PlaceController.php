<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Place\PlaceRequest;
use App\Services\PlaceApi;

class PlaceController extends BaseController
{
    public function __construct(
        protected PlaceApi $placeApi,
    )
    {
    }

    public function index(PlaceRequest $request)
    {
        $places = $this->placeApi->search_by_city($request->getPlace());

        return response()->json($places);
    }
}
