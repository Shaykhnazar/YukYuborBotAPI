<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface BaseRepositoryInterface
{
    public function find(int $id): ?Model;

    public function findOrFail(int $id): Model;

    public function findBy(string $field, $value): ?Model;

    public function findWhere(array $conditions): Collection;

    public function create(array $data): Model;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function deleteWhere(array $conditions): int;

    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function all(): Collection;

    public function count(): int;

    public function exists(array $conditions): bool;
}