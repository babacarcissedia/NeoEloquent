<?php

namespace Vinelab\NeoEloquent\Eloquent;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laudis\Neo4j\Types\Node;
use InvalidArgumentException;
use Vinelab\NeoEloquent\Helpers;
use Laudis\Neo4j\Types\CypherList;
use Illuminate\Pagination\Paginator;
use Vinelab\NeoEloquent\Traits\ResultTrait;
use Illuminate\Pagination\LengthAwarePaginator;
use Vinelab\NeoEloquent\Eloquent\Relations\Relation;
use Vinelab\NeoEloquent\Query\Builder as QueryBuilder;
use Vinelab\NeoEloquent\Eloquent\Relationship as EloquentRelationship;

class Builder extends \Illuminate\Database\Eloquent\Builder
{
    use ResultTrait;

    /**
     * The loaded models that should be transformed back
     * to Models. Sometimes we might ask for more than
     * a model in a query, a quick example is eager loading. We request
     * the relationship and return both models so that when the Node placeholder
     * is detected and found within the mutations we will try to build
     * a new instance of that model with the builder attributes.
     *
     * @var array
     */
    protected array $mutations = [];

    public function __construct($query)
    {
        $this->query = $query;
    }
    /**
     * The methods that should be returned from query builder.
     */
    //    protected array $passthru = [
    //        'insert', 'insertGetId', 'getBindings', 'toSql',
    //        'exists', 'count', 'min', 'max', 'avg', 'sum',
    //    ];

    /**
     * Find a model by its primary key.
     *
     * @param mixed $id
     * @param mixed $properties
     *
     * @return Model|static|null|Collection
     */
    public function find($id, $properties = ['*'])
    {
        // If the dev did not specify the $id as an int it would break
        // so we cast it anyways.
        // edit: not anymore
        // array_map('intval', $id)
        if (is_array($id)) {
            return $this->findMany($id, $properties);
        }

        // TODO: remove id
        //        if ($this->model->getKeyName() === 'id') {
        //            // ids are treated differently in neo4j so we have to adapt the query to them.
        //            $this->query->where($this->model->getKeyName() . '(' . $this->query->modelAsNode() . ')', '=', (int) $id);
        //        } else {
        $this->query->where($this->model->getKeyName(), '=', $id);
        //        }

        return $this->first($properties);
    }

    /**
     * Get the hydrated models without eager loading.
     *
     * @param array $properties
     *
     * @return array|static[]
     */
    public function getModels($properties = ['*'])
    {
        // First, we will simply get the raw results from the query builders which we
        // can use to populate an array with Eloquent models. We will pass columns
        // that should be selected as well, which are typically just everything.

        $results = $this->query->get($properties);
        $models = $this->resultsToModels($this->model->getConnectionName(), $results);
        // hold the unique results (discarding duplicates resulting from the query)

        // $unique = [];

        // FIXME: when we detect relationships, we need to remove duplicate
        // records returned by query.

        // $index = 0;
        // if (!empty($this->mutations)) {
        //     foreach ($results->getRelationships() as $relationship) {
        //         $unique[] = $models[$index];
        //         ++$index;
        //     }

        //     $models = $unique;
        // }

        // Once we have the results, we can spin through them and instantiate a fresh
        // model instance for each records we retrieved from the database. We will
        // also set the proper connection name for the model after we create it.
        return $models;
    }

    /**
     * Eagerly load the relationship on a set of models.
     *
     * @param array    $models
     * @param string   $name
     * @param Closure $constraints
     *
     * @return array
     */
    protected function loadRelation(array $models, $name, Closure $constraints)
    {
        // First we will "back up" the existing where conditions on the query so we can
        // add our eager constraints. Then we will merge the wheres that were on the
        // query back to it in order that any where conditions might be specified
        // to be taken into consideration with the query.
        $relation = $this->getRelation($name);

        // First we will check for existing relationships in models
        // if that exists then we'll have to take out the end models
        // from the relationships - this happens in the case of
        // nested relations.
        // if ($this->hasRelationships($models)) {
        //     $models = array_map(function($model) {
        //         return $model->getEndModel();
        //     }, $models);
        // }

        $relation->addEagerConstraints($models);

        call_user_func($constraints, $relation);

        $models = $relation->initRelation($models, $name);

        // Once we have the results, we just match those back up to their parent models
        // using the relationship instance. Then we just return the finished arrays
        // of models which have been eagerly hydrated and are readied for return.
        $results = $relation->getEager();

        return $relation->match($models, $results, $name);
    }

    /**
     * Determines whether the given array includes instances
     * of \Vinelab\NeoEloquent\Eloquent\Relationship.
     *
     * @param array $models
     *
     * @return bool
     */
    protected function hasRelationships(array $models)
    {
        $itDoes = false;

        foreach ($models as $model) {
            if ($model instanceof EloquentRelationship) {
                $itDoes = true;

                break;
            }
        }

        return $itDoes;
    }
    /**
     * Turn Neo4j result set into the corresponding model.
     *
     * @param string $connection
     * @param ?CypherList $results
     *
     * @return array
     */
    protected function resultsToModels($connection, $results = null)
    {
        $models = [];

        $results = $results ?? new CypherList();

        if ($results) {
            $resultsByIdentifier = $this->getRecordsByPlaceholders($results);
            $relationships = $this->getRelationshipRecords($results);

            if (! empty($relationships) && ! empty($this->mutations)) {
                $startIdentifier = $this->getStartNodeIdentifier($resultsByIdentifier, $relationships);
                $endIdentifier = $this->getEndNodeIdentifier($resultsByIdentifier, $relationships);

                foreach ($relationships as $index => $resultRelationship) {
                    $startModelClass = $this->getMutationModel($startIdentifier);
                    $endModelClass = $this->getMutationModel($endIdentifier);

                    if ($this->shouldMutate($endIdentifier) && $this->isMorphMutation($endIdentifier)) {
                        $models[] = $this->mutateToOrigin($results, $resultsByIdentifier);
                    } else {
                        $startNode = (is_array($resultsByIdentifier[$startIdentifier])) ? $resultsByIdentifier[$startIdentifier][$index] : reset($resultsByIdentifier[$startIdentifier]);
                        $endNode = (is_array($resultsByIdentifier[$endIdentifier])) ? $resultsByIdentifier[$endIdentifier][$index] : reset($resultsByIdentifier[$endIdentifier]);
                        $models[] = [
                            $startIdentifier => $this->newModelFromNode($startNode, $startModelClass, $connection),
                            $endIdentifier => $this->newModelFromNode($endNode, $endModelClass, $connection),
                        ];
                    }
                }
            } else {
                foreach ($resultsByIdentifier as $identifier => $nodes) {
                    if ($this->shouldMutate($identifier)) {
                        $models[] = $this->mutateToOrigin($results, $resultsByIdentifier);
                    } else {
                        foreach ($nodes as $result) {
                            if ($result instanceof Node) {
                                $model = $this->newModelFromNode($result, $this->model, $connection);
                                $models[] = $model;
                            }
                        }
                    }
                }
            }
        }

        return $models;
    }

    protected function getStartNodeIdentifier($resultsByIdentifier, $relationships)
    {
        return $this->getNodeIdentifier($resultsByIdentifier, $relationships, 'start');
    }

    protected function getEndNodeIdentifier($resultsByIdentifier, $relationships)
    {
        return $this->getNodeIdentifier($resultsByIdentifier, $relationships, 'end');
    }

    protected function getNodeIdentifier($resultsByIdentifier, $relationships, $type = 'start')
    {
        $method = 'getStartNodeId';

        if ($type === 'end') {
            $method = 'getEndNodeId';
        }

        $relationship = reset($relationships);

        foreach ($resultsByIdentifier as $identifier => $nodes) {
            foreach ($nodes as $node) {
                if ($node->getId() === $relationship->$method()) {
                    return $identifier;
                }
            }
        }
    }

    /**
     * Get a Model instance out of the given node.
     *
     * @param Node $node
     * @param Model $model
     * @param string $connection
     *
     * @return Model
     */
    public function newModelFromNode(Node $node, Model $model, $connection = null)
    {
        // let's begin with a proper connection
        if (! $connection) {
            $connection = $model->getConnectionName();
        }

        // get the attributes ready
        $attributes = array_merge($node->getProperties()->toArray(), $model->getAttributes());

        // we will check to see whether we should use Neo4j's built-in ID.
        // TODO: remove id
        //        if ($model->getKeyName() === 'id') {
        //            $attributes['id'] = $node->getId();
        //        }

        // This is a regular record that we should deal with the normal way, creating an instance
        // of the model out of the fetched attributes.
        $fresh = $model->newFromBuilder($attributes);
        $fresh->setConnection($connection);

        return $fresh;
    }

    /**
     * Turn Neo4j result set into the corresponding model with its relations.
     *
     * @param string                                            $connection
     * @param CypherList    $results
     *
     * @return array
     */
    protected function resultsToModelsWithRelations($connection, CypherList $results)
    {
        $models = [];

        if (! $results->isEmpty()) {
            $grammar = $this->getQuery()->getGrammar();

            //            $nodesByIdentifier = $results->getAllByIdentifier();
            //
            //            foreach ($nodesByIdentifier as $identifier => $nodes) {
            //                // Now that we have the attributes, we first check for mutations
            //                // and if exists, we will need to mutate the attributes accordingly.
            //                if ($this->shouldMutate($identifier)) {
            //                    foreach ($nodes as $node) {
            //                        $attributes = $node->getProperties();
            //                        $cropped = $grammar->cropLabelIdentifier($identifier);
            //
            //                        if (!isset($models[$cropped])) {
            //                            $models[$cropped] = [];
            //                        }
            //
            //                        if (isset($this->mutations[$cropped])) {
            //                            $mutationModel = $this->getMutationModel($cropped);
            //                            $models[$cropped][] = $this->newModelFromNode($node, $mutationModel);
            //                        }
            //                    }
            //                }
            //            }

            $recordsByPlaceholders = $this->getRecordsByPlaceholders($results);

            foreach ($recordsByPlaceholders as $placeholder => $records) {
                // Now that we have the attributes, we first check for mutations
                // and if exists, we will need to mutate the attributes accordingly.
                if ($this->shouldMutate($placeholder)) {
                    $cropped = $grammar->cropLabelIdentifier($placeholder);
                    //                    $attributes = $record->values();

                    foreach ($records as $record) {
                        if (! isset($models[$cropped])) {
                            $models[$cropped] = [];
                        }

                        if (isset($this->mutations[$cropped])) {
                            $mutationModel = $this->getMutationModel($cropped);
                            $models[$cropped][] = $this->newModelFromNode($record, $mutationModel);
                        }
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Mutate a result back into its original Model.
     *
     * @param mixed $result
     * @param array $attributes
     *
     * @return array
     */
    public function mutateToOrigin($result, $attributes)
    {
        $mutations = [];

        // Transform mutations back to their origin
        foreach ($attributes as $mutation => $values) {
            // First we should see whether this mutation can be resolved so that
            // we take it into consideration otherwise we skip to the next iteration.
            if (! $this->resolvableMutation($mutation)) {
                continue;
            }

            // Since this mutation should be resolved by us then we check whether it is
            // a Many or One mutation.
            if ($this->isManyMutation($mutation)) {
                $mutations = $this->mutateManyToOrigin($attributes);
            }
            // Dealing with Morphing relations requires that we determine the morph_type out of the relationship
            // and mutating back to that class.
            elseif ($this->isMorphMutation($mutation)) {
                $mutant = $this->mutateMorphToOrigin($result, $attributes);

                if ($this->getMutation($mutation)['type'] == 'morphEager') {
                    $mutations[$mutation] = $mutant;
                } else {
                    $mutations = reset($mutant);
                }
            }
            // Dealing with One mutations is simply returning an associative array with the mutation
            // label being the $key and the related model is $value.
            else {
                $node = current($values);
                $mutations[$mutation] = $this->newModelFromNode($node, $this->getMutationModel($mutation));
            }
        }

        return $mutations;
    }

    /**
     * In the case of Many mutations we need to return an associative array having both
     * relations as a single record so that when we match them back we know which result
     * belongs to which parent node.
     *
     * @param array $attributes
     */
    public function mutateManyToOrigin($results)
    {
        $mutations = [];

        foreach ($this->getMutations() as $label => $info) {
            $mutationModel = $this->getMutationModel($label);
            $mutations[$label] = $this->newModelFromNode(current($results[$label]), $mutationModel);
        }

        return $mutations;
    }

    protected function mutateMorphToOrigin($result, $attributesByLabel)
    {
        $mutations = [];

        foreach ($this->getMorphMutations() as $label => $info) {
            // Let's see where we should be getting the morph Class name from.
            $mutationModelProperty = $this->getMutationModel($label);
            // We need the relationship from the result since it has the mutation model property's
            // value being the model that we should mutate to as set earlier by a HyperEdge.
            // NOTE: 'r' is statically set in CypherGrammer to represent the relationship.
            // Now we have an \Everyman\Neo4j\Relationship instance that has our morph class name.
            /** @var \Laudis\Neo4j\Types\Relationship $relationship */
            $relationship = current($this->getRelationshipRecords($result));
            // Get the morph class name.
            $class = $relationship->getProperties()->get($mutationModelProperty);

            // we need the model attributes though we might receive a nested
            // array that includes them on level 2 so we check
            // whether what we have is the array of attrs
            if (! Helpers::isAssocArray($attributesByLabel[$label])) {
                $attributes = current($attributesByLabel[$label]);

                if ($attributes instanceof Node) {
                    $attributes = $this->getNodeAttributes($attributes);
                }
            } else {
                $attributes = $attributesByLabel[$label];
            }
            // Create a new instance of it from builder.
            $model = (new $class())->newFromBuilder($attributes);
            // And that my friend, is our mutations model =)
            $mutations[] = $model;
        }

        return $mutations;
    }

    /**
     * Determine whether attributes are mutations
     * and should be transformed back. It is considered
     * a mutation only when the attributes' keys
     * and mutations keys match.
     *
     * @param array $attributes
     *
     * @return bool
     */
    public function shouldMutate($identifier)
    {
        $grammar = $this->getQuery()->getGrammar();
        $identifier = $grammar->cropLabelIdentifier($identifier);
        $mutations = array_keys($this->mutations);

        return in_array($identifier, $mutations);
    }

    /**
     * Get the properties (attributes in Eloquent terms)
     * out of a result row.
     *
     * @param array                     $columns The columns retrieved by the result
     * @param Row $row
     * @param array                     $columns
     *
     * @return array
     *
     * @deprecated 2.0 using getNodeAttributes instead
     */
    public function getProperties(array $resultColumns, Row $row)
    {
        dd('Get Properties, Everyman dependent');
        $attributes = [];

        $columns = $this->query->columns;

        // What we get returned from the client is a result set
        // and each result is either a Node or a single column value
        // so we first extract the returned value and retrieve
        // the attributes according to the result type.

        // Only when requesting a single property
        // will we extract the current() row of result.

        $current = $row->current();

        $result = ($current instanceof Node) ? $current : $row;

        if ($this->isRelationship($resultColumns)) {
            // You must have chosen certain properties (columns) to be returned
            // which means that we should map the values to their corresponding keys.
            foreach ($resultColumns as $key => $property) {
                $value = $row[$property];

                if ($value instanceof Node) {
                    $value = $this->getNodeAttributes($value);
                } else {
                    // Our property should be extracted from the query columns
                    // instead of the result columns
                    $property = $columns[$key];

                    // as already assigned, RETURNed props will be preceded by an 'n.'
                    // representing the node we're targeting.
                    $returned = $this->query->modelAsNode() . ".{$property}";

                    $value = $row[$returned];
                }

                $attributes[$property] = $value;
            }

            // If the node id is in the columns we need to treat it differently
            // since Neo4j's convenience with node ids will be retrieved as id(n)
            // instead of n.id.

            // WARNING: Do this after setting all the attributes to avoid overriding it
            // with a null value or colliding it with something else, some Daenerys dragons maybe ?!
            // TODO: id removal
            //            if (! is_null($columns) && in_array('id', $columns)) {
            //                $attributes['id'] = $row['id(' . $this->query->modelAsNode() . ')'];
            //            }
        } elseif ($result instanceof Node) {
            $attributes = $this->getNodeAttributes($result);
        } elseif ($result instanceof Row) {
            $attributes = $this->getRowAttributes($result, $columns, $resultColumns);
        }

        return $attributes;
    }

    /**
     * Gather the properties of a Node including its id.
     *
     * @return array
     */
    public function getNodeAttributes(Node $node)
    {
        // Extract the properties of the node
        $attributes = $node->getProperties()->toArray();

        // Add the node id to the attributes since \Everyman\Neo4j\Node
        // does not consider it to be a property, it is treated differently
        // and available through the getId() method.
        // TODO: remove id
        $attributes['_elementId'] = $node->getId();

        return $attributes;
    }

    /**
     * Get the attributes of a result Row.
     *
     * @param Row $row
     * @param array                     $columns       The query columns
     * @param array                     $resultColumns The result columns that can be extracted from a \Everyman\Neo4j\Query\ResultSet
     *
     * @return array
     */
    public function getRowAttributes(Row $row, $columns, $resultColumns)
    {
        $attributes = [];

        foreach ($resultColumns as $key => $column) {
            $attributes[$columns[$key]] = $row[$column];
        }

        return $attributes;
    }

    /**
     * Add an INCOMING "<-" relationship MATCH to the query.
     *
     * @param Vinelab\NeoEloquent\Eloquent\Model $parent       The parent model
     * @param Vinelab\NeoEloquent\Eloquent\Model $related      The related model
     * @param string                             $relationship
     *
     * @return Vinelab\NeoEloquent\Eloquent|static
     */
    public function matchIn($parent, $related, $relatedNode, $relationship, $property, $value = null, $boolean = 'and')
    {
        // Add a MATCH clause for a relation to the query
        $this->query->matchRelation($parent, $related, $relatedNode, $relationship, $property, $value, 'in', $boolean);

        return $this;
    }

    /**
     * Add an OUTGOING "->" relationship MATCH to the query.
     *
     * @param Vinelab\NeoEloquent\Eloquent\Model $parent       The parent model
     * @param Vinelab\NeoEloquent\Eloquent\Model $related      The related model
     * @param string                             $relationship
     *
     * @return Vinelab\NeoEloquent\Eloquent|static
     */
    public function matchOut($parent, $related, $relatedNode, $relationship, $property, $value = null, $boolean = 'and')
    {
        $this->query->matchRelation($parent, $related, $relatedNode, $relationship, $property, $value, 'out', $boolean);

        return $this;
    }

    /**
     * Add an outgoing morph relationship to the query,
     * a morph relationship usually ignores the end node type since it doesn't know
     * what it would be so we'll only set the start node and hope to get it right when we match it.
     *
     * @param Vinelab\NeoEloquent\Eloquent\Model $parent
     * @param string                             $relatedNode
     * @param string                             $property
     * @param mixed                              $value
     *
     * @return Vinelab\NeoEloquent\Eloquent|static
     */
    public function matchMorphOut($parent, $relatedNode, $property, $value = null, $boolean = 'and')
    {
        $this->query->matchMorphRelation($parent, $relatedNode, $property, $value, $boolean);

        return $this;
    }

    /**
     * Paginate the given query.
     *
     * @param int      $perPage
     * @param array    $columns
     * @param string   $pageName
     * @param int|null $page
     *
     * @throws InvalidArgumentException
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $total = $this->query->getCountForPagination();

        $this->query->forPage(
            $page = $page ?: Paginator::resolveCurrentPage($pageName),
            $perPage = $perPage ?: $this->model->getPerPage()
        );

        return new LengthAwarePaginator($this->get($columns), $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Update a record in the database.
     *
     * @param array $values
     *
     * @return int
     */
    public function update(array $values)
    {
        return $this->query->update($this->addUpdatedAtColumn($values));
    }

    /**
     * Add a mutation to the query.
     *
     * @param string                                     $holder
     * @param Model|string $model  String in the case of morphs where we do not know
     *                                                           the morph model class name
     */
    public function addMutation($holder, $model, $type = 'one')
    {
        $this->mutations[$holder] = [
            'type' => $type,
            'model' => $model,
        ];
    }

    /**
     * Add a mutation of the type 'many' to the query.
     *
     * @param string                              $holder
     * @param Model $model
     */
    public function addManyMutation($holder, Model $model)
    {
        $this->addMutation($holder, $model, 'many');
    }

    /**
     * Add a mutation of the type 'morph' to the query.
     *
     * @param string $holder
     * @param string $model
     */
    public function addMorphMutation($holder, $model = 'morph_type')
    {
        return $this->addMutation($holder, $model, 'morph');
    }

    /**
     * Add a mutation of the type 'morph' to the query.
     *
     * @param string $holder
     * @param string $model
     */
    public function addEagerMorphMutation($holder, $model = 'morph_type')
    {
        return $this->addMutation($holder, $model, 'morphEager');
    }

    /**
     * Determine whether a mutation is of the type 'many'.
     *
     * @param string $mutation
     *
     * @return bool
     */
    public function isManyMutation($mutation)
    {
        return isset($this->mutations[$mutation]) && $this->mutations[$mutation]['type'] === 'many';
    }

    /**
     * Determine whether this mutation is of the typ 'morph'.
     *
     * @param string $mutation
     *
     * @return bool
     */
    public function isMorphMutation($mutation)
    {
        if (! is_array($mutation) && isset($this->mutations[$mutation])) {
            $mutation = $this->getMutation($mutation);
        }

        return $mutation['type'] === 'morph' || $mutation['type'] === 'morphEager';
    }

    /**
     * Get the mutation model.
     *
     * @param string $mutation
     *
     * @return Vinelab\NeoEloquent\Eloquent\Model
     */
    public function getMutationModel($mutation)
    {
        if ($this->mutationExists($mutation)) {
            return $this->getMutation($mutation)['model'];
        }
    }

    /**
     * Determine whether a mutation of the given type exists.
     *
     * @param string $mutation
     *
     * @return bool
     */
    public function mutationExists($mutation)
    {
        return isset($this->mutations[$mutation]);
    }

    /**
     * Get the mutation type.
     *
     * @param string $mutation
     *
     * @return string
     */
    public function getMutationType($mutation)
    {
        return $this->getMutation($mutation)['type'];
    }

    /**
     * Determine whether a mutation can be resolved
     * by simply checking whether it exists in the $mutations.
     *
     * @param string $mutation
     *
     * @return bool
     */
    public function resolvableMutation($mutation)
    {
        return isset($this->mutations[$mutation]);
    }

    /**
     * Get the mutations.
     *
     * @return array
     */
    public function getMutations()
    {
        return $this->mutations;
    }

    /**
     * Get a single mutation.
     *
     * @param string $mutation
     *
     * @return array
     */
    public function getMutation($mutation)
    {
        return $this->mutations[$mutation];
    }

    /**
     * Get the mutations of type 'morph'.
     *
     * @return array
     */
    public function getMorphMutations()
    {
        return array_filter($this->getMutations(), function ($mutation) { return $this->isMorphMutation($mutation); });
    }

    /**
     * Determine whether the intended result is a relationship result between nodes,
     * we can tell by the format of the requested properties, in case the requested
     * properties were in the form of 'user.name' we are pretty sure it is an attribute
     * of a node, otherwise if they're plain strings like 'user' and they're more than one then
     * the reference is assumed to be a Node placeholder rather than a property.
     *
     * @param Row $row
     *
     * @return bool
     */
    public function isRelationship(array $columns)
    {
        $matched = array_filter($columns, function ($column) {
            // As soon as we find that a property does not
            // have a dot '.' in it we assume it is a relationship,
            // unless it is the id of a node which is where we look
            // at a pattern that matches id(any character here).
            if (preg_match('/^([a-zA-Z0-9-_]+\.[a-zA-Z0-9-_]+)|(id\(.*\))$/', $column)) {
                return false;
            }

            return true;
        });

        return  count($matched) > 1 ? true : false;
    }

    /**
     * Add a relationship query condition.
     *
     * @param string   $relation
     * @param string   $operator
     * @param int      $count
     * @param string   $boolean
     * @param Closure $callback
     *
     * @return Builder|static
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', Closure $callback = null)
    {
        if (strpos($relation, '.') !== false) {
            return $this->hasNested($relation, $operator, $count, $boolean, $callback);
        }

        $relation = $this->getHasRelationQuery($relation);

        $query = $relation->getRelated()->newQuery();
        // This will make sure that any query we add here will consider the related
        // model as our reference Node. Similar to switching contexts.
        $this->getQuery()->from = $query->getModel()->nodeLabel();

        /*
         * In graph we do not need to act on the count of the relationships when dealing
         * with a whereHas() since the database will not return the result unless a relationship
         * exists between two nodes.
         */
        $prefix = $relation->getRelatedNode();

        if ($callback) {
            call_user_func($callback, $query);
            $this->query->matches = array_merge($this->query->matches, $query->getQuery()->matches);
            $this->query->with = array_merge($this->query->with, $query->getQuery()->with);
            $this->carry([$relation->getParentNode(), $relation->getRelatedNode()]);
        } else {
            /*
             * The Cypher we're trying to build here would look like this:
             *
             * MATCH (post:`Post`)-[r:COMMENT]-(comments:`Comment`)
             * WITH count(comments) AS comments_count, post
             * WHERE comments_count >= 10
             * RETURN post;
             *
             * Which is the result of Post::has('comments', '>=', 10)->get();
             */
            $countPart = $prefix . '_count';
            $this->carry([$relation->getParentNode(), "count({$prefix})" => $countPart]);
            $this->whereCarried($countPart, $operator, $count);
        }

        $parentNode = $relation->getParentNode();
        $relatedNode = $relation->getRelatedNode();
        // Tell the query to select our parent node only.
        $this->select($parentNode);
        // Set the relationship match clause.
        $method = $this->getMatchMethodName($relation);

        $this->$method(
            $relation->getParent(),
            $relation->getRelated(),
            $relatedNode,
            $relation->getRelationType(),
            $relation->getLocalKey(),
            $relation->getParentLocalKeyValue(),
            $boolean
        );

        // Prefix all the columns with the relation's node placeholder in the query
        // and merge the queries that needs to be merged.
        $this->prefixAndMerge($query, $prefix);

        /*
         * After that we've done everything we need with the Has() and related we need
         * to reset the query for the grammar so that whenever we continu querying we make
         * sure that we're using the correct grammar. i.e.
         *
         * $user->whereHas('roles', function(){})->where('id', $user->id)->first();
         */
        $grammar = $this->getQuery()->getGrammar();
        $grammar->setQuery($this->getQuery());
        $this->getQuery()->from = $this->getModel()->nodeLabel();

        return $this;
    }

    /**
     * Add nested relationship count conditions to the query.
     *
     * @param string        $relations
     * @param string        $operator
     * @param int           $count
     * @param string        $boolean
     * @param Closure|null $callback
     *
     * @return Builder|static
     */
    protected function hasNested($relations, $operator = '>=', $count = 1, $boolean = 'and', $callback = null)
    {
        $relations = explode('.', $relations);

        // In order to nest "has", we need to add count relation constraints on the
        // callback Closure. We'll do this by simply passing the Closure its own
        // reference to itself so it calls itself recursively on each segment.
        $closure = function ($q) use (&$closure, &$relations, $operator, $count, $boolean, $callback) {
            if (count($relations) > 1) {
                $q->whereHas(array_shift($relations), $closure);
            } else {
                $q->has(array_shift($relations), $operator, $count, 'and', $callback);
            }
        };

        return $this->has(array_shift($relations), '>=', 1, $boolean, $closure);
    }

    /**
     * Add a relationship count condition to the query.
     *
     * @param string        $relation
     * @param string        $boolean
     * @param Closure|null $callback
     *
     * @return Builder|static
     */
    public function doesntHave($relation, $boolean = 'and', Closure $callback = null)
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add a relationship count condition to the query with an "or".
     *
     * @param string $relation
     * @param string $operator
     * @param int    $count
     *
     * @return Builder|static
     */
    public function orHas($relation, $operator = '>=', $count = 1)
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Add a relationship count condition to the query with where clauses.
     *
     * @param string        $relation
     * @param Closure|null $callback
     *
     * @return Builder|static
     */
    public function whereDoesntHave($relation, Closure $callback = null)
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Merge the "wheres" from a relation query to a has query.
     *
     * @param Builder $hasQuery
     * @param Relation $relation
     */
    protected function mergeWheresToHas(Builder $hasQuery, Relation $relation)
    {
        // Here we have the "has" query and the original relation. We need to copy over any
        // where clauses the developer may have put in the relationship function over to
        // the has query, and then copy the bindings from the "has" query to the main.
        $relationQuery = $relation->getBaseQuery();

        $hasQuery = $hasQuery->getModel()->removeGlobalScopes($hasQuery);

        $hasQuery->mergeWheres(
            $relationQuery->wheres,
            $relationQuery->getBindings()
        );

        $this->query->mergeBindings($hasQuery->getQuery());
    }

    /**
     * Get the "has relation" base query instance.
     *
     * @param string $relation
     *
     * @return Builder
     */
    protected function getHasRelationQuery($relation)
    {
        return Relation::noConstraints(function () use ($relation) {
            return $this->getModel()->$relation();
        });
    }

    /**
     * Parse a list of relations into individuals.
     *
     * @param array $relations
     *
     * @return array
     */
    protected function parseRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            // If the "relation" value is actually a numeric key, we can assume that no
            // constraints have been specified for the eager load and we'll just put
            // an empty Closure with the loader so that we can treat all the same.
            if (is_numeric($name)) {
                $f = function () {};

                [$name, $constraints] = [$constraints, $f];
            }

            // We need to separate out any nested includes. Which allows the developers
            // to load deep relationships using "dots" without stating each level of
            // the relationship with its own key in the array of eager load names.
            $results = $this->parseNested($name, $results);

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Parse the nested relationships in a relation.
     *
     * @param string $name
     * @param array  $results
     *
     * @return array
     */
    protected function parseNested($name, $results)
    {
        $progress = [];

        // If the relation has already been set on the result array, we will not set it
        // again, since that would override any constraints that were already placed
        // on the relationships. We will only set the ones that are not specified.
        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;

            if (! isset($results[$last = implode('.', $progress)])) {
                $results[$last] = function () {};
            }
        }

        return $results;
    }

    /**
     * Get the underlying query builder instance.
     *
     * @return QueryBuilder|static
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set the underlying query builder instance.
     *
     * @param QueryBuilder $query
     *
     * @return $this
     */
    public function setQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get the relationships being eagerly loaded.
     *
     * @return array
     */
    public function getEagerLoads()
    {
        return $this->eagerLoad;
    }

    /**
     * Set the relationships being eagerly loaded.
     *
     * @param array $eagerLoad
     *
     * @return $this
     */
    public function setEagerLoads(array $eagerLoad)
    {
        $this->eagerLoad = $eagerLoad;

        return $this;
    }

    /**
     * Get the model instance being queried.
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Extend the builder with a given callback.
     *
     * @param string   $name
     * @param Closure $callback
     */
    public function macro($name, Closure $callback)
    {
        $this->macros[$name] = $callback;
    }

    /**
     * Get the given macro by name.
     *
     * @param string $name
     *
     * @return Closure
     */
    public function getMacro($name)
    {
        return Arr::get($this->macros, $name);
    }

    /**
     * Create a new record from the parent Model and new related records with it.
     *
     * @param array $attributes
     * @param array $relations
     *
     * @return Model
     */
    public function createWith(array $attributes, array $relations)
    {
        // Collect the model attributes and label in the form of ['label' => $label, 'attributes' => $attributes]
        // as expected by the Query Builder.
        $attributes = $this->prepareForCreation($this->model, $attributes);
        $model = ['label' => $this->model->nodeLabel(), 'attributes' => $attributes];

        /*
         * Collect the related models in the following for as expected by the Query Builder:
         *
         *  [
         *       'label' => ['Permission'],
         *       'relation' => [
         *           'name' => 'photos',
         *           'type' => 'PHOTO',
         *           'direction' => 'out',
         *       ],
         *       'values' => [
         *           // A mix of models and attributes, doesn't matter really..
         *           ['url' => '', 'caption' => ''],
         *           ['url' => '', 'caption' => '']
         *       ]
         *  ]
         */
        $related = [];

        foreach ($relations as $relation => $values) {
            $name = $relation;

            // Get the relation by calling the model's relationship function.
            if (! method_exists($this->model, $relation)) {
                throw new QueryException("The relation method {$relation}() does not exist on " . get_class($this->model));
            }

            $relationship = $this->model->$relation();
            // Bring the model from the relationship.
            $relatedModel = $relationship->getRelated();

            // We will first check to see what the dev have passed as values
            // so that we make sure that we have an array moving forward
            // In the case of a model Id or an associative array or a Model instance it means that
            // this is probably a One-To-One relationship or the dev decided not to add
            // multiple records as relations so we'll wrap it up in an array.
            if (
                (! is_array($values) || Helpers::isAssocArray($values) || $values instanceof Model)
                && ! ($values instanceof Collection)
            ) {
                $values = [$values];
            }

            $id = $relatedModel->getKeyName();
            $label = $relationship->getRelated()->nodeLabel();
            $direction = $relationship->getEdgeDirection();
            $type = $relationship->getRelationType();

            // Hold the models that we need to attach
            $attach = [];
            // Hold the models that we need to create
            $create = [];

            // Separate the models that needs to be attached from the ones that needs
            // to be created.
            foreach ($values as $value) {
                // If this is a Model then the $exists property will indicate what we need
                // so we'll add its id to be attached.
                if ($value instanceof Model and $value->exists === true) {
                    $attach[] = $value->getKey();
                }
                // Next we will check whether we got a Collection in so that we deal with it
                // accordingly, which guarantees sending an Eloquent result straight in would work.
                elseif ($value instanceof Collection) {
                    $attach = array_merge($attach, $value->lists('id'));
                }
                // Or in the case where the attributes are neither an array nor a model instance
                // then this is assumed to be the model Id that the dev means to attach and since
                // Neo4j node Ids are always an int then we take that as a value.
                elseif (! is_array($value) && ! $value instanceof Model) {
                    $attach[] = $value;
                }
                // In this case the record is considered to be new to the market so let's create it.
                else {
                    $create[] = $this->prepareForCreation($relatedModel, $value);
                }
            }

            $relation = compact('name', 'type', 'direction');
            $related[] = compact('relation', 'label', 'create', 'attach', 'id');
        }

        $results = $this->query->createWith($model, $related);
        $models = $this->resultsToModelsWithRelations($this->model->getConnectionName(), $results);

        return (! empty($models)) ? $models : null;
    }

    /**
     * Prepare model's attributes or instance for creation in a query.
     *
     * @param string $class
     * @param mixed  $attributes
     *
     * @return array
     */
    protected function prepareForCreation($class, $attributes)
    {
        // We need to get the attributes of each $value from $values into
        // an instance of the related model so that we make sure that it goes
        // through the $fillable filter pipeline.

        // This adds support for having model instances mixed with values, so whenever
        // we encounter a Model we take it as our instance
        if ($attributes instanceof Model) {
            $instance = $attributes;
        }
        // Reaching here means the dev entered raw attributes (similar to insert())
        // so we'll need to pass the attributes through the model to make sure
        // the fillables are respected as expected by the dev.
        else {
            $instance = new $class($attributes);
        }

        // Update timestamps on the instance, this will only affect newly
        // created models by adding timestamps to them, otherwise it has no effect
        // on existing models.
        if ($instance->usesTimestamps()) {
            $instance->addTimestamps();
        }

        return $instance->toArray();
    }

    /**
     * Prefix query bindings and wheres with the relation's model Node placeholder.
     *
     * @param Builder $query
     * @param string                                $prefix
     */
    protected function prefixAndMerge(Builder $query, $prefix)
    {
        if (is_array($query->getQuery()->wheres)) {
            $query->getQuery()->wheres = $this->prefixWheres($query->getQuery()->wheres, $prefix);
        }

        $this->query->mergeWheres($query->getQuery()->wheres, $query->getQuery()->getBindings());
    }

    /**
     * Prefix where clauses' columns.
     *
     * @param array  $wheres
     * @param string $prefix
     *
     * @return array
     */
    protected function prefixWheres(array $wheres, $prefix)
    {
        dump(array_map(function ($where) use ($prefix) {
            if ($where['type'] == 'Nested') {
                $where['query']->wheres = $this->prefixWheres($where['query']->wheres, $prefix);
            } elseif ($where['type'] != 'Carried' && strpos($where['column'], '.') == false) {
                $column = $where['column'];
                // TODO: remove id
                $where['column'] = $prefix . '.' . $column;
                //                $where['column'] = ($this->isId($column)) ? $column : $prefix . '.' . $column;
            }

            return $where;
        }, $wheres));

        return array_map(function ($where) use ($prefix) {
            if ($where['type'] == 'Nested') {
                $where['query']->wheres = $this->prefixWheres($where['query']->wheres, $prefix);
            } elseif ($where['type'] != 'Carried' && strpos($where['column'], '.') == false) {
                $column = $where['column'];
                // TODO: remove id
                $where['column'] = $prefix . '.' . $column;
                //                $where['column'] = ($this->isId($column)) ? $column : $prefix . '.' . $column;
            }

            return $where;
        }, $wheres);
    }

    /**
     * Determine whether a value is an Id attribute according to Neo4j.
     *
     * @param string $value
     *
     * @return bool
     */
    public function isId($value)
    {
        return preg_match('/^id(\(.*\))?$/', $value);
    }

    /**
     * Get the match[In|Out] method name out of a relation.
     *
     * @param * $relation
     *
     * @return [type]
     */
    protected function getMatchMethodName($relation)
    {
        return 'match' . ucfirst(mb_strtolower($relation->getEdgeDirection()));
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Add the "updated at" column to an array of values.
     *
     * @param  array  $values
     * @return array
     */
    protected function addUpdatedAtColumn(array $values)
    {
        if (! $this->model->usesTimestamps() ||
            is_null($this->model->getUpdatedAtColumn())) {
            return $values;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! array_key_exists($column, $values)) {
            $timestamp = $this->model->freshTimestampString();

            if (
                $this->model->hasSetMutator($column)
                || $this->model->hasAttributeSetMutator($column)
                || $this->model->hasCast($column)
            ) {
                $timestamp = $this->model->newInstance()
                    ->forceFill([$column => $timestamp])
                    ->getAttributes()[$column] ?? $timestamp;
            }

            $values = array_merge([$column => $timestamp], $values);
        }

        $segments = preg_split('/\s+as\s+/i', $this->query->from);

        // Only real change here
        $qualifiedColumn = strtolower(end($segments)) . '.' . $column;

        $values[Str::replace('.', '_', $qualifiedColumn)] = Arr::get($values, $qualifiedColumn, $values[$column]);

        // End Only real change here

        unset($values[$column]);

        return $values;
    }
}
