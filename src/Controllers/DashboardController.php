<?php

class DashboardController
{
    public function index(): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }
        Auth::home();
    }
}