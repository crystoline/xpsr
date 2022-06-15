<?php

namespace App\Http\Controller;

use AdapterHighChart;
use Hospital;
use Psr\Http\Message\RequestInterface;
use Statistics;
use Sunrise\Http\Message\ResponseFactory;

class HomeController
{

    public function index(RequestInterface $request){

        return view('home.index');
    }



}