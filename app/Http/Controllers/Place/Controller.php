<?php

namespace App\Http\Controllers\Place;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Place\IndexRequest;
use App\Service\PlaceApi;

class Controller extends BaseController
{
    public function __construct(
        protected PlaceApi $placeApi,
    )
    {
    }

    public function index(IndexRequest $request)
    {
        $places = $this->placeApi->search_by_city($request->getPlace());

        return response()->json($places);
    }
}
