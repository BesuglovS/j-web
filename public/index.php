<?php

declare(strict_types=1);

// Фронт-контроллер: вся маршрутизация через него.
require __DIR__ . '/../src/bootstrap.php';

Database::ensureSchema();

$router = require __DIR__ . '/../src/routes.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = $router->currentPath();

echo $router->dispatch($method, $path);