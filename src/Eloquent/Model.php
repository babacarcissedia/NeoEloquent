<?php

namespace Vinelab\NeoEloquent\Eloquent;

use BadMethodCallException;
use Vinelab\NeoEloquent\Helpers;
use Vinelab\NeoEloquent\Eloquent\Relations\HasOne;
use Vinelab\NeoEloquent\Eloquent\Relations\HasMany;
use Vinelab\NeoEloquent\Eloquent\Relations\BelongsTo;
use Vinelab\NeoEloquent\Eloquent\Relations\MorphMany;
use Vinelab\NeoEloquent\Eloquent\Relations\HyperMorph;
use Vinelab\NeoEloquent\Query\Builder as QueryBuilder;
use Vinelab\NeoEloquent\Eloquent\Relations\MorphedByOne;
use Vinelab\NeoEloquent\Eloquent\Relations\BelongsToMany;
use Vinelab\NeoEloquent\Eloquent\Builder as EloquentBuilder;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    /**
     * The node label.
     *
     * @var string|array
     */
    protected $label = null;

    /**
     * Set the node label for this model.
     *
     * @param string|array $labels
     */
    public function setLabel($label)
    {
        return $this->label = $label;
    }

    /**
     * @override
     * Get the node label for this model.
     *
     * @return string|array
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @override
     * Create a new Eloquent query builder for the model.
     *
     * @param \Vinelab\NeoEloquent\Query\Builder $query
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Builder|static
     */
    public function newEloquentBuilder($query)
    {
        return new EloquentBuilder($query);
    }

    /**
     * @override
     * Get a new query builder instance for the connection.
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();

        $grammar = $conn->getQueryGrammar();

        return new QueryBuilder($conn, $grammar);
    }

    /**
     * Get the node labels.
     *
     * @return array
     */
    public function getDefaultNodeLabel()
    {
        // by default we take the $label, otherwise we consider $table
        // for Eloquent's backward compatibility
        $label = (empty($this->label)) ? $this->table : $this->label;

        // The label is accepted as an array for a convenience so we need to
        // convert it to a string separated by ':' following Neo4j's labels
        if (is_array($label) && ! empty($label)) {
            return $label;
        }

        // since this is not an array, it is assumed to be a string
        // we check to see if it follows neo4j's labels naming (User:Fan)
        // and return an array exploded from the ':'
        if (! empty($label)) {
            $label = array_filter(explode(':', $label));

            // This trick re-indexes the array
            array_splice($label, 0, 0);

            return $label;
        }

        // Since there was no label for this model
        // we take the fully qualified (namespaced) class name and
        // pluck out backslashes to get a clean 'WordsUp' class name and use it as default
        return [str_replace('\\', '', get_class($this))];
    }

    /**
     * @override
     * Get the table associated with the model.
     *
     * @return string
     */
    public function nodeLabel()
    {
        return $this->getDefaultNodeLabel();
    }

    /**
     * @override
     * Define an inverse one-to-one or many relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @param string $otherKey
     * @param string $relation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function belongsTo($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the calling class, which
        // will be uppercased and used as a relationship label
        if (is_null($foreignKey)) {
            $foreignKey = strtoupper($caller['class']);
        }

        $instance = new $related();

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $query = $instance->newQuery();

        $otherKey = $otherKey ?: $instance->getKeyName();

        return new BelongsTo($query, $this, $foreignKey, $otherKey, $relation);
    }

    /**
     * @override
     * Define a one-to-one relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function hasOne($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the calling class, which
        // will be uppercased and used as a relationship label
        if (is_null($foreignKey)) {
            $foreignKey = strtoupper($caller['class']);
        }

        $instance = new $related();

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $query = $instance->newQuery();

        $otherKey = $otherKey ?: $instance->getKeyName();

        return new HasOne($query, $this, $foreignKey, $otherKey, $relation);
    }

    /**
     * @override
     * Define a one-to-many relationship.
     *
     * @param string $related
     * @param string $type
     * @param string $key
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Relations\HasMany
     */
    public function hasMany($related, $type = null, $key = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // the $type should be the UPPERCASE of the relation not the foreign key.
        $type = $type ?: mb_strtoupper($relation);

        $instance = new $related();

        $key = $key ?: $this->getKeyName();

        return new HasMany($instance->newQuery(), $this, $type, $key, $relation);
    }

    /**
     * @override
     * Define a many-to-many relationship.
     *
     * @param string $related
     * @param string $type
     * @param string $key
     * @param string $relation
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Relations\BelongsToMany
     */
    public function belongsToMany(
        $related,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null
    ) {
        // To escape the error:
        // PHP Strict standards:  Declaration of Vinelab\NeoEloquent\Eloquent\Model::belongsToMany() should be
        //      compatible with Illuminate\Database\Eloquent\Model::belongsToMany()
        // We'll just map them in with the variables we want.
        $type = $table;
        $key = $foreignPivotKey;
        $relation = $relatedPivotKey;

        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no $key was provided we will consider it the key name of this model.
        $key = $key ?: $this->getKeyName();

        // If no relationship type was provided, we can use the previously traced back
        // $relation being the function name that called this method and using it in its
        // all uppercase form.
        if (is_null($type)) {
            $type = mb_strtoupper($relation);
        }

        $instance = new $related();

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for the relation. The relations will set
        // appropriate query constraint and entirely manages the hydrations.
        $query = $instance->newQuery();

        return new BelongsToMany($query, $this, $type, $key, $relation);
    }

    /**
     * @override
     * Create a new HyperMorph relationship.
     *
     * @param \Vinelab\NeoEloquent\Eloquent\Model $model
     * @param string                              $related
     * @param string                              $type
     * @param string                              $morphType
     * @param string                              $relation
     * @param string                              $key
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Relations\HyperMorph
     */
    public function hyperMorph($model, $related, $type = null, $morphType = null, $relation = null, $key = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no $key was provided we will consider it the key name of this model.
        $key = $key ?: $this->getKeyName();

        // If no relationship type was provided, we can use the previously traced back
        // $relation being the function name that called this method and using it in its
        // all uppercase form.
        if (is_null($type)) {
            $type = mb_strtoupper($relation);
        }

        $instance = new $related();

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for the relation. The relations will set
        // appropriate query constraint and entirely manages the hydrations.
        $query = $instance->newQuery();

        return new HyperMorph($query, $this, $model, $type, $morphType, $key, $relation);
    }

    /**
     * @override
     * Define a many-to-many relationship.
     *
     * @param string $related
     * @param string $type
     * @param string $key
     * @param string $relation
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Relations\MorphMany
     */
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        // To escape the error:
        // Strict standards: Declaration of Vinelab\NeoEloquent\Eloquent\Model::morphMany() should be
        //          compatible with Illuminate\Database\Eloquent\Model::morphMany()
        // We'll just map them in with the variables we want.
        $relationType = $name;
        $key = $type;
        $relation = $id;

        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no $key was provided we will consider it the key name of this model.
        $key = $key ?: $this->getKeyName();

        // If no relationship type was provided, we can use the previously traced back
        // $relation being the function name that called this method and using it in its
        // all uppercase form.
        if (is_null($relationType)) {
            $relationType = mb_strtoupper($relation);
        }

        $instance = new $related();

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for the relation. The relations will set
        // appropriate query constraint and entirely manages the hydrations.
        $query = $instance->newQuery();

        return new MorphMany($query, $this, $relationType, $key, $relation);
    }

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * @param string $related
     * @param string $name
     * @param string $type
     * @param string $id
     * @param string $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function morphOne($related, $name, $type = null, $id = null, $localKey = null)
    {
        $instance = new $related();

        [$type, $id] = $this->getMorphs($name, $type, $id);

        $table = $instance->nodeLabel();

        $localKey = $localKey ?: $this->getKeyName();

        return new MorphOne($instance->newQuery(), $this, $table . '.' . $type, $table . '.' . $id, $localKey);
    }

    /**
     * @override
     * Create an inverse one-to-one polymorphic relationship with specified model and relation.
     *
     * @param \Vinelab\NeoEloquent\Eloquent\Model $related
     * @param string                              $type
     * @param string                              $key
     * @param string                              $relation
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Relations\MorphedByOne
     */
    public function morphedByOne($related, $type, $key = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            [, $caller] = debug_backtrace(false);

            $relation = $caller['function'];
        }

        // If no $key was provided we will consider it the key name of this model.
        $key = $key ?: $this->getKeyName();

        // If no relationship type was provided, we can use the previously traced back
        // $relation being the function name that called this method and using it in its
        // all uppercase form.
        if (is_null($type)) {
            $type = mb_strtoupper($relation);
        }

        $instance = new $related();

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for the relation. The relations will set
        // appropriate query constraint and entirely manages the hydrations.
        $query = $instance->newQuery();

        return new MorphedByOne($query, $this, $type, $key, $relation);
    }

    /**
     * Register a saving model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function saving($callback, $priority = 0)
    {
        static::registerModelEvent('saving', $callback, $priority);
    }

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function updated($callback, $priority = 0)
    {
        static::registerModelEvent('updated', $callback, $priority);
    }

    /**
     * Register a creating model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function creating($callback, $priority = 0)
    {
        static::registerModelEvent('creating', $callback, $priority);
    }

    /**
     * Register a created model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function created($callback, $priority = 0)
    {
        static::registerModelEvent('created', $callback, $priority);
    }

    /**
     * Register a deleting model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function deleting($callback, $priority = 0)
    {
        static::registerModelEvent('deleting', $callback, $priority);
    }

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function deleted($callback, $priority = 0)
    {
        static::registerModelEvent('deleted', $callback, $priority);
    }

    /**
     * Register an updating model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function updating($callback, $priority = 0)
    {
        static::registerModelEvent('updating', $callback, $priority);
    }

    /**
     * Register a saved model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @param int             $priority
     */
    public static function saved($callback, $priority = 0)
    {
        static::registerModelEvent('saved', $callback, $priority);
    }

    /**
     * Create a model with its relations.
     *
     * @param array $attributes
     * @param array $relations
     * @param array $options
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Model
     */
    public static function createWith(array $attributes, array $relations, array $options = [])
    {
        // we need to fire model events on all the models that are involved with our operaiton,
        // including the ones from the relations, starting with this model.
        $me = new static();
        $me->fill($attributes);
        $models = [$me];

        $query = static::query();
        $grammar = $query->getQuery()->getGrammar();

        // add parent model's mutation constraints
        $label = $grammar->modelAsNode($me->getDefaultNodeLabel());
        $query->addManyMutation($label, $me);

        // setup relations
        foreach ($relations as $relation => $values) {
            $related = $me->$relation()->getRelated();

            // if the relation holds the attributes directly instead of an array
            // of attributes, we transform it into an array of attributes.
            if ((! is_array($values) || Helpers::isAssocArray($values)) && ! $values instanceof Collection) {
                $values = [$values];
            }

            // create instances with the related attributes so that we fire model
            // events on each of them.
            foreach ($values as $relatedModel) {
                // one may pass in either instances or arrays of attributes, when we get
                // attributes we will dynamically fill a new model instance of the related model.
                if (is_array($relatedModel)) {
                    $model = $related->newInstance();
                    $model->fill($relatedModel);
                    $relatedModel = $model;
                }

                $models[$relation][] = $relatedModel;
                $query->addManyMutation($relation, $related);
            }
        }

        $existingModelsKeys = [];

        // fire 'creating' and 'saving' events on all models.
        foreach ($models as $relation => $related) {
            if (! is_array($related)) {
                $related = [$related];
            }

            foreach ($related as $model) {
                // we will fire model events on actual models, however attached models using IDs will not be considered.
                if ($model instanceof Model) {
                    if (! $model->exists && $model->fireModelEvent('creating') === false) {
                        return false;
                    }

                    if($model->exists) {
                        $existingModelsKeys[] = $model->getKey();
                    }

                    if ($model->fireModelEvent('saving') === false) {
                        return false;
                    }
                } else {
                    $existingModelsKeys[] = $model;
                }
            }
        }

        // remove $me from $models so that we send them as relations.
        array_shift($models);
        // run the query and create the records.
        $result = $query->createWith($me->toArray(), $models);
        // take the parent model that was created out of the results array based on
        // this model's label.
        $created = reset($result[$label]);
        // fire 'saved' and 'created' events on parent model.
        $created->finishSave($options);
        $created->fireModelEvent('created', false);

        // set related models as relations on the parent model.
        foreach ($relations as $method => $values) {
            $relation = $created->$method();
            // is this a one-to-one relation ? If so then we add the model directly,
            // otherwise we create a collection of the loaded models.
            $related = new Collection($result[$method]);
            // fire model events 'created' and 'saved' on related models.
            $related->each(function ($model) use ($options, $existingModelsKeys) {
                $model->finishSave($options);

                if(! in_array($model->getKey(), $existingModelsKeys)) {
                    $model->fireModelEvent('created', false);
                }
            });

            // when the relation is 'One' instead of 'Many' we will only return the retrieved instance
            // instead of colletion.
            if ($relation instanceof OneRelation || $relation instanceof HasOne || $relation instanceof BelongsTo) {
                $related = $related->first();
            }

            $created->setRelation($method, $related);
        }

        return $created;
    }
    /**
     * Add visible attributes for the model.
     *
     * @param array|string|null $attributes
     */
    public function addVisible($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->visible = array_merge($this->visible, $attributes);
    }
    /**
     * Add timestamps to this model.
     */
    public function addTimestamps()
    {
        $this->updateTimestamps();
    }

    /*
     * Adds more labels
     * @param $labels array of strings containing labels to be added
     * @return bull true if success, false if failure
     */
    public function addLabels($labels)
    {
        return $this->updateLabels($labels, 'add');
    }

    /*
     * Drops labels
     * @param $labels array of strings containing labels to be dropped
     * @return bull true if success, false if failure
     */
    public function dropLabels($labels)
    {
        return $this->updateLabels($labels, 'drop');
    }

    /*
     * Adds or Drops labels
     * @param $labels array of strings containing labels to be dropped
     * @param $operation string can be 'add' or 'drop'
     * @return bull true if success, false if failure
     */
    public function updateLabels($labels, $operation = 'add')
    {
        $query = $this->newQueryWithoutScopes();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This gives an opportunities to
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if (! is_array($labels) || count($labels) == 0) {
            return false;
        }

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z]([a-z0-9]+)$/i', $label)) {
                return false;
            }
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll return false.
        if ($this->exists) {
            $this->setKeysForSaveQuery($query)->updateLabels($labels, $operation);
            $this->fireModelEvent('updated', false);
        } else {
            return false;
        }
    }
    /**
     * When a model is being unserialized, check if it needs to be booted.
     */
    public function __wakeup()
    {
        $this->bootIfNotBooted();
    }

    /**
     * Get the queueable connection for the entity.
     *
     * @return mixed
     */
    public function getQueueableConnection()
    {
        return $this->getConnectionName();
    }

    public function getQueueableRelations()
    {
        throw new BadMethodCallException('NeoEloquent does not support queueable relations yet');
    }

    public function resolveChildRouteBinding($childType, $value, $field)
    {
        throw new BadMethodCallException('NeoEloquent does not support queueable relations yet');
    }
}
