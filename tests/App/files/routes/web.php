<?php

use App\Nitro\Pages\Article;
use App\Nitro\Pages\Counter;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::nitro('/counter', Counter::class);
Route::nitro('/article', Article::class);
