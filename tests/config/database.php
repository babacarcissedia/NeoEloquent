<?php

return [
    'default' => 'bolt+routing',

    'connections' => [
        'neo4j' => [
            'driver' => 'neo4j',
            'host' => 'neo4j',
            'port' => 7687,
            'username' => 'neo4j',
            'password' => 'test',
        ],

        'default' => [
            'driver' => 'neo4j',
            'host' => 'neo4j',
            'port' => 7687,
            'username' => 'neo4j',
            'password' => 'test',
        ],
    ],
];
