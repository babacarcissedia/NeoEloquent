<?php

namespace Vinelab\NeoEloquent\Schema;

use Closure;
use Laudis\Neo4j\Types\CypherList;

class Builder extends \Illuminate\Database\Schema\Builder
{
    /**
     * The Blueprint resolver callback.
     *
     * @var Closure
     */
    protected $resolver;

    /**
     * Fallback.
     *
     * @param string $label
     *
     * @throws RuntimeException
     * @return bool
     *
     */
    public function hasTable($label)
    {
        throw new \RuntimeException("
Please use commands from namespace:
    neo4j:
    neo4j:migrate
    neo4j:migrate:make
    neo4j:migrate:reset
    neo4j:migrate:rollback
If your default database is set to 'neo4j' and you want use other databases side by side with Neo4j
you can do so by passing additional arguments to default migration command like:
    php artisan neo4j:migrate --database=other-neo4j
        ");
    }

    /**
     * Create a new data defintion on label schema.
     *
     * @param string  $label
     * @param Closure $callback
     *
     * @return \Vinelab\NeoEloquent\Schema\Blueprint
     */
    public function label($label, Closure $callback)
    {
        return $this->build(
            $this->createBlueprint($label, $callback)
        );
    }

    /**
     * Drop a label from the schema.
     *
     * @param string $label
     *
     * @return \Vinelab\NeoEloquent\Schema\Blueprint
     */
    public function drop($label)
    {
        $blueprint = $this->createBlueprint($label);

        $blueprint->drop();

        return $this->build($blueprint);
    }

    /**
     * Drop a label from the schema if it exists.
     *
     * @param string $label
     *
     * @return \Vinelab\NeoEloquent\Schema\Blueprint
     */
    public function dropIfExists($label)
    {
        $blueprint = $this->createBlueprint($label);

        $blueprint->dropIfExists();

        return $this->build($blueprint);
    }

    /**
     * Determine if the given label exists.
     *
     * @param string $label
     *
     * @return bool
     */
    public function hasLabel($label)
    {
        $cypher = $this->conn->getSchemaGrammar()->compileLabelExists($label);

        return $this->getConnection()->select($cypher, [])->count() > 0;
    }

    /**
     * Determine if the given relation exists.
     *
     * @param string $relation
     *
     * @return bool
     */
    public function hasRelation($relation)
    {
        $cypher = $this->conn->getSchemaGrammar()->compileRelationExists($relation);

        return $this->getConnection()->select($cypher, [])->count() > 0;
    }

    /**
     * Rename a label.
     *
     * @param string $from
     * @param string $to
     *
     * @return \Vinelab\NeoEloquent\Schema\Blueprint|bool
     */
    public function renameLabel($from, $to)
    {
        $blueprint = $this->createBlueprint($from);

        $blueprint->renameLabel($to);

        return $this->build($blueprint);
    }

    /**
     * Execute the blueprint to modify the label.
     *
     * @param Blueprint $blueprint
     */
    protected function build($blueprint)
    {
        return $blueprint->build(
            $this->getConnection(),
            $this->connection->getSchemaGrammar()
        );
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param string  $label
     * @param Closure $callback
     *
     * @return \Vinelab\NeoEloquent\Schema\Blueprint
     */
    protected function createBlueprint($label, Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $label, $callback);
        } else {
            return new Blueprint($label, $callback);
        }
    }

    /**
     * Set the Schema Blueprint resolver callback.
     *
     * @param \Closure $resolver
     */
    public function blueprintResolver(Closure $resolver)
    {
        $this->resolver = $resolver;
    }

    public function dropAllTables()
    {
        $this->connection->statement('match (n) detach delete n');
    }

    public function getColumnListing($table)
    {
        /** @var CypherList $results */
        $results = $this->getColumns($table)->getResults();
        $columns = [];

        if ($results->count() == 0) {
            return [];
        }

        foreach ($results->first()->get('columns') as $value) {
            $columns[] = $value;
        }

        return $columns;
    }
}
