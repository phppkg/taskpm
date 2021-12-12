<?php

use PhpPkg\TaskPM\TaskManager;

require dirname(__DIR__) . '/test/bootstrap.php';

// run: php example/map-reduce.php
TaskManager::new()
    ->onMaster(function (TaskManager $mgr) {
        $mgr->submit([1, 1000]);

        sleep(3);
        $mgr->submit([1001, 2000]);

        sleep(3);
        $mgr->submit([2001, 3000], function ($data, TaskManager $mgr) {
            $mgr->log('submit 3', ['ctx' => $data]);

            sleep(3);
            $mgr->submit([3001, 4000]);
        });

        // $mgr->wait(3000);
    })
    ->onWorker(function ($params, TaskManager $mgr) {
        // slave process's callback cannot print anything, print log please use $fm->log()
        $mgr->log('on slave', $params);
        return $params;
    })
    ->wait(3000);