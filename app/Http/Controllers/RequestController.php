<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;

class RequestController extends Controller
{
    public function index(ParcelRequest $request)
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
