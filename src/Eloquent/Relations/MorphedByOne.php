<?php

namespace Vinelab\NeoEloquent\Eloquent\Relations;

use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\Eloquent\Edges\EdgeOut;

class MorphedByOne extends OneRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            /*
             * For belongs to relationships, which are essentially the inverse of has one
             * or has many relationships, we need to actually query on the primary key
             * of the parent model matching on the INCOMING relationship by name.
             *
             * We are trying to achieve a Cypher that goes something like:
             *
             * MATCH (phone:`Phone`), (phone)<-[:PHONE]-(owner:`User`)
             * WHERE id(phone) = 1006
             * RETURN owner;
             *
             * (phone:`Phone`) represents a matching statement where
             * 'phone' is the parent Node's placeholder and '`Phone`' is the parentLabel.
             * All node placeholders must be lowercased letters and will be used
             * throught the query to represent the actual Node.
             *
             * Resulting from:
             * class Phone extends NeoEloquent {
             *
             *     public function owner()
             *     {
             *          return $this->belongsTo('User', 'PHONE');
             *     }
             * }
            */

            // Get the parent node's placeholder.
            $parentNode = $this->query->getQuery()->modelAsNode($this->parent->nodeLabel());
            // Tell the query that we only need the related model returned.
            $this->query->select($this->relation);
            // Set the parent node's placeholder as the RETURN key.
            $this->query->getQuery()->from = [$parentNode];
            // Build the MATCH ()<-[]-() Cypher clause.
            $this->query->matchOut($this->parent, $this->related, $this->relation, $this->relationType, $this->otherKey, $this->parent->{$this->otherKey});
            // Add WHERE clause over the parent node's matching key = value.
            $this->query->where($this->otherKey, '=', $this->parent->{$this->otherKey});
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array $models
     */
    public function addEagerConstraints(array $models)
    {
        /*
         * We'll grab the primary key name of the related models since it could be set to
         * a non-standard name and not "id". We will then construct the constraint for
         * our eagerly loading query so it returns the proper models from execution.
         */

        // Grab the parent node placeholder
        $parentNode = $this->query->getQuery()->modelAsNode($this->parent->nodeLabel());

        // Tell the builder to select both models of the relationship
        $this->query->select($this->relation, $parentNode);

        // Setup for their mutation so they don't breed weird stuff like... humans ?!
        $this->query->addMutation($this->relation, $this->related);
        $this->query->addMutation($parentNode, $this->parent);

        // Set the parent node's placeholder as the RETURN key.
        $this->query->getQuery()->from = [$parentNode];
        // Build the MATCH ()<-[]-() Cypher clause.
        $this->query->matchOut($this->parent, $this->related, $this->relation, $this->relationType, $this->otherKey, $this->parent->{$this->otherKey});
        // Add WHERE clause over the parent node's matching keys [values...].
        $this->query->whereIn($this->otherKey, $this->getEagerModelKeys($models));
    }

    /**
     * Get an instance of the EdgeIn relationship.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array                               $attributes
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Edges\EdgeOut
     */
    public function getEdge(Model $model = null, $attributes = [])
    {
        $model = (! is_null($model)) ? $model : $this->parent->{$this->relation};

        // Indicate a unique relation since this only involves one other model.
        $unique = true;

        return new EdgeOut($this->query, $this->parent, $model, $this->relationType, $attributes, $unique);
    }
}
