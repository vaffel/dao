<?php
namespace Vaffel\Dao\Models\Interfaces;

interface Indexable
{
    public function getIndexableDocument();
    public function getIndexName();
    public function getIndexType();
}
