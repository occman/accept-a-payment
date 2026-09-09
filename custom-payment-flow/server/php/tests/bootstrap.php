<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Keep error_log() output from the handlers under test out of the test output.
ini_set('error_log', sys_get_temp_dir() . '/php-unit-tests-error.log');
