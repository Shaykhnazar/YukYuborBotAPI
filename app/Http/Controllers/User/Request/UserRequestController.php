<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;
use App\Models\User;

class UserRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {}

    public function index(ParcelRequest $request)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);
        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) {
                $item->type = 'send';
                return $item;
            });;
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });;
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }

    public function show(ParcelRequest $request, int $id)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->where('id', $id)->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->where('id', $id)->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }
    public function userRequests(ParcelRequest $request, User $user)
    {
        $filter = $request->getFilter();

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }
}
