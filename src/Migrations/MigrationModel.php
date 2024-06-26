<?php

namespace Vinelab\NeoEloquent\Migrations;

use Vinelab\NeoEloquent\Eloquent\Model as NeoEloquent;

class MigrationModel extends NeoEloquent
{
    /**
     * {@inheritDoc}
     */
    protected $label = 'NeoEloquentMigration';

    /**
     * {@inheritDoc}
     */
    protected $fillable = [
        'migration',
        'batch',
    ];

    /**
     * {@inheritDoc}
     */
    protected $guarded = [];
}
