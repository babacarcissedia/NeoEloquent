<?php

namespace Vinelab\NeoEloquent\Query\Processors;

class Processor extends \Illuminate\Database\Query\Processors\Processor
{
    public function processInsertGetId($query, $cypher, $values, $sequence = null)
    {
        $query->getConnection()->insert($cypher, $values);

        $id = $query->getConnection()->getPdo()->lastInsertId($sequence);

        return is_numeric($id) ? (int) $id : $id;
    }
}
