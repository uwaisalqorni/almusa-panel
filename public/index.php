<?php

define('PANEL_ROOT', dirname(__DIR__));

require_once PANEL_ROOT . '/app/bootstrap.php';
require_once PANEL_ROOT . '/app/routes.php';

\App\Core\Router::dispatch();
