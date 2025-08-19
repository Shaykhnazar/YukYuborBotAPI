<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->model->findOrFail($id);
    }

    public function findBy(string $field, $value): ?Model
    {
        return $this->model->where($field, $value)->first();
    }

    public function findWhere(array $conditions): Collection
    {
        $query = $this->model->newQuery();

        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                [$operator, $val] = $value;
                $query->where($field, $operator, $val);
            } else {
                $query->where($field, $value);
            }
        }

        return $query->get();
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return $this->model->where('id', $id)->update($data);
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function deleteWhere(array $conditions): int
    {
        $query = $this->model->newQuery();

        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                [$operator, $val] = $value;
                $query->where($field, $operator, $val);
            } else {
                $query->where($field, $value);
            }
        }

        return $query->delete();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->paginate($perPage);
    }

    public function all(): Collection
    {
        return $this->model->all();
    }

    public function count(): int
    {
        return $this->model->count();
    }

    public function exists(array $conditions): bool
    {
        $query = $this->model->newQuery();

        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                [$operator, $val] = $value;
                $query->where($field, $operator, $val);
            } else {
                $query->where($field, $value);
            }
        }

        return $query->exists();
    }
}