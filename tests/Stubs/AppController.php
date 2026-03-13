<?php

declare(strict_types=1);

namespace App\Http\Controllers;

if (class_exists(Controller::class, false)) {
    return;
}

abstract class Controller {}
