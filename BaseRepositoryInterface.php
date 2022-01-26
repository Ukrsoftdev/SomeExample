<?php

namespace SomePath\Core\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use TransferLineModel;

interface BaseRepositoryInterface
{
    /**
     * Gets a model class name
     *
     * @return string Model Class Name
     */
    public function model(): string;

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
    public function makeModel(): Model;

    /**
     * BaseRepository constructor.
     *
     * @param Model $model Model instance
     *
     */
    public function setModel(Model $model): BaseRepositoryInterface;

    /**
     * Gets a model instance
     *
     * @return \Illuminate\Database\Eloquent\Model|mixed Model instance
     */
    public function getModel();

    /**
     * Gets query builder of the model
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery();

    /**
     * Returns the count of all the records.
     *
     * @return int Count
     */
    public function count(): int;

    /**
     * Creates a record
     *
     * @param mixed[] $data Record data
     *
     * @return \Illuminate\Database\Eloquent\Model Created instance
     */
    public function make(array $data): Model;

    /**
     * Adds multiple records to the database at once
     *
     * @param array $data Array of arrays with data
     *
     * @return bool Whether the insert was successful
     */
    public function create(array $data): bool;

    /**
     * Updates a model
     *
     * @param string  $id         Model Id
     * @param mixed[] $attributes Model attributes
     *
     * @return bool True - successfully updated, otherwise - false
     * @throws \Exception On any error
     */
    public function update($id, $attributes): bool;

    /**
     * Removes a model or a list of models
     *
     * @param string|string[] $id Model Id(s)
     *
     * @return int Number of deleted records
     */
    public function destroy($id): int;

    /**
     * Finds(or fails) a model with a given id
     *
     * @param string $id Model Id
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection Model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail($id);

    /**
     * Find a record by its identifier.
     *
     * @param string|int $id        Model id
     * @param array      $relations Model relations
     *
     * @return \Illuminate\Database\Eloquent\Model|null Model
     */
    public function find($id, $relations = null);

    /**
     * Find a record by an attribute.
     * Fails if no model is found.
     *
     * @param string $attribute Attribute name
     * @param string $value     Attribute value
     * @param array  $relations Relations
     *
     * @return \Illuminate\Database\Eloquent\Model|null Model
     */
    public function findBy($attribute, $value, $relations = null);

    /**
     * Fills out an instance of the model with $attributes.
     *
     * @param mixed[] $attributes Attributes
     *
     * @return \Illuminate\Database\Eloquent\Model Model
     */
    public function fill($attributes): Model;

    /**
     * Fills out an instance of the model and saves it, pretty much like mass assignment.
     *
     * @param mixed[] $attributes Attributes
     *
     * @return bool True if no errors, otherwise - false
     */
    public function fillAndSave($attributes): bool;

    /**
     * Gets records by primary keys
     *
     * @param int[]    $ids       List of primary keys
     * @param string[] $relations List of relations
     * @param string[] $columns   List of columns for select
     *
     * @return \Illuminate\Database\Eloquent\Builder[]|Collection List of found records
     */
    public function getByIds(array $ids, array $relations = [], array $columns = ['*']);

    /**
     * Persists a given model
     *
     * @param Model $model Model
     */
    public function persist(Model $model);

    /**
     * Checks if an entity exists by ID
     *
     * @param int|string $id Entity ID
     *
     * @return bool
     */
    public function existsById($id): bool;
}
