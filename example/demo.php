<?php

use PhpPkg\TaskPM\TaskManager;

require dirname(__DIR__) . '/test/bootstrap.php';

TaskManager::new()
    ->onMaster(function () {

    })
    ->onWorker(function () {

    })
    ->wait();
