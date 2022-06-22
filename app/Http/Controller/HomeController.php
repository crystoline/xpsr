<?php

namespace App\Http\Controller;

use Crystoline\Xpsr\Application;
use Psr\Http\Message\RequestInterface;

class HomeController
{

    public function index(RequestInterface $request){
        return view('home.index');
    }



}