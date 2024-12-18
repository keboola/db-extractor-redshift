<?php

declare(strict_types=1);

use Keboola\DbExtractor\FunctionalTests\DatabaseManager;
use Keboola\DbExtractor\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    $manager = new DatabaseManager($test->getConnection());

    // sales table
    $manager->createSalesTable();

    // escaping table
    $manager->createEscapingTable();
    $manager->createEscapingTable(schema: 'public2');

    // late-bind view table
    $manager->createSalesLateBindView('sales2', ['usercity', 'zipcode']);
};
