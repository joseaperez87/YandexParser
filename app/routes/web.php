<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

Route::fallback(fn () => view('app'));
