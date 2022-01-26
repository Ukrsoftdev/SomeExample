<?php

namespace SomePath\Core\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class BaseRepository
 *
 * @package App\Repositories
 */
abstract class BaseRepository
{
    /** @var array Aliases for fields */
    protected static $map = [];

    /** @var \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder $model Model Instance */
    private $model;

    /**
     * Gets a model class name
     *
     * @return string Model Class Name
     */
    abstract public function model(): string;

    /**
     * @return Model
     *
     * Makes a model object
     *
     * @return \Illuminate\Database\Eloquent\Model|mixed Model
     *
     * @throws \InvalidArgumentException                                  If model wasn't created
     * @throws \Illuminate\Contracts\Container\BindingResolutionException If we can't create model
     */
    public function makeModel(): Model
    {
        $model = app()->make($this->model());
        if (!$model instanceof Model) {
            throw new \InvalidArgumentException(
                "Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model"
            );
        }

        $this->setModel($model);

        return $model;
    }

    /**
     * @param Model $model
     * @return BaseRepositoryInterface
     */
    public function setModel(Model $model): BaseRepositoryInterface
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Gets a model instance
     *
     * @return \Illuminate\Database\Eloquent\Model Model instance
     */
    public function getModel(): Model
    {
        if (null === $this->model) {
            $this->makeModel();
        }

        return $this->model;
    }

    /**
     * Gets query builder of the model
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery(): Builder
    {
        return $this->getModel()->newQuery();
    }

    /**
     * Returns the count of all the records.
     *
     * @return int Count
     */
    public function count(): int
    {
        return $this->getModel()->count();
    }

    /**
     * Creates a record
     *
     * @param mixed[] $data Record data
     * @return \Illuminate\Database\Eloquent\Model Created instance
     */
    public function make(array $data): Model
    {
        return $this->makeModel()->fill($data);
    }


    /**
     * Adds multiple records to the database at once
     *
     * @param array $data Array of arrays with data
     * @return bool Whether the insert was successful
     */
    public function create(array $data): bool
    {
        return  $this->make($data)->save();
    }


    /**
     * Updates a model
     *
     * @param string $id Model Id
     * @param mixed[] $attributes Model attributes
     *
     * @return bool True - successfully updated, otherwise - false
     * @throws \Exception On any error
     */
    public function update($id, $attributes): bool
    {
        $this->setModel($this->findOrFail($id));
        $this->getModel()->fill($attributes);

        return $this->getModel()->save();
    }

    /**
     * Removes a model or a list of models
     *
     * @param string|string[] $id Model Id(s)
     * @return int Number of deleted records
     */
    public function destroy($id): int
    {
        return $this->getModel()->destroy($id);
    }

    /**
     * Finds(or fails) a model with a given id
     *
     * @param string $id Model Id
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail($id)
    {
        return $this->getModel()->findOrFail($id);
    }

    /**
     * Find a record by its identifier.
     *
     * @param string|int $id Model id
     * @param array $relations Model relations
     * @return \Illuminate\Database\Eloquent\Model|null Model
     */
    public function find($id, $relations = null): ?Model
    {
        return $this->findBy($this->getModel()->getKeyName(), $id, $relations);
    }

    /**
     * Find a record by an attribute.
     * Fails if no model is found.
     *
     * @param string $attribute Attribute name
     * @param string $value Attribute value
     * @param array $relations Relations
     * @return \Illuminate\Database\Eloquent\Model|null Model
     */
    public function findBy($attribute, $value, $relations = null): ?Model
    {
        $query = $this->getModel()->where($attribute, $value);

        if ($relations && \is_array($relations)) {
            foreach ($relations as $relation) {
                $query->with($relation);
            }
        }
        return $query->first();
    }

    /**
     * Fills out an instance of the model with $attributes.
     *
     * @param mixed[] $attributes Attributes
     * @return \Illuminate\Database\Eloquent\Model Model
     */
    public function fill($attributes): Model
    {
        return $this->getModel()->fill($attributes);
    }

    /**
     * Fills out an instance of the model and saves it, pretty much like mass assignment.
     *
     * @param mixed[] $attributes Attributes
     * @return bool True if no errors, otherwise - false
     */
    public function fillAndSave($attributes): bool
    {
        $this->makeModel()->fill($attributes);

        return $this->getModel()->save();
    }

    /**
     * Gets records by primary keys
     *
     * @param int[] $ids List of primary keys
     * @param string[] $relations List of relations
     * @param string[] $columns List of columns for select
     *
     * @return \Illuminate\Database\Eloquent\Builder[]|Collection List of found records
     */
    public function getByIds(array $ids, array $relations = [], array $columns = ['*'])
    {
        return $this
            ->newQuery()
            ->whereIn($this->getModel()->getKeyName(), $ids)
            ->with($relations)
            ->get($columns);
    }

    /**
     * Persists a given model
     *
     * @param Model $model Model
     */
    public function persist(Model $model)
    {
        $model->save();
    }

    /**
     * Checks if an entity exists by ID
     *
     * @param int|string $id Entity ID
     * @return bool
     */
    public function existsById($id): bool
    {
        $model = $this->getModel();

        return $model->where($model->getKeyName(), $id)->exists();
    }
}
