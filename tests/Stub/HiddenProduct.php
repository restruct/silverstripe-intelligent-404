<?php

namespace Restruct\Silverstripe\Intelligent404\Tests\Stub;

/**
 * A subclass of a configured `data_objects` class that declares ShowInSearch itself, while the
 * configured parent (Product) does not. Proves that `exclude_hidden_from_search` reaches records of
 * such a subclass: a schema check on the configured class alone only sees its ancestors' fields.
 *
 * Kept concrete on purpose, like Product.
 */
class HiddenProduct extends Product
{
    # Short table name rather than the FQCN-derived default
    private static $table_name = 'I404HiddenProduct';

    private static $db = [
        'ShowInSearch' => 'Boolean',
    ];

    # Visible unless unticked, like SiteTree
    private static $defaults = [
        'ShowInSearch' => true,
    ];
}
