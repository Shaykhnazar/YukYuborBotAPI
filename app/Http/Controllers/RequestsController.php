<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\IndexRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;

class RequestsController extends Controller
{
    public function index(IndexRequest $request)
    {
        $filter = $request->getFilter();
        $delivery = collect();
        $send = collect();

        if ($filter !== 'send') {
            $delivery = DeliveryRequest::with('user.telegramUser')
                ->where('status', 'open')
                ->get()
                ->map(function ($item) {
                    $item->type = 'delivery';
                    return $item;
                });
        }

        if ($filter !== 'delivery') {
            $send = SendRequest::with('user.telegramUser')
                ->where('status', 'open')
                ->get()
                ->map(function ($item) {
                    $item->type = 'send';
                    return $item;
                });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }
}
