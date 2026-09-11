<?php

class DashboardController
{
    public function index(): void
    {
        // Гостя requireLogin отправит прямо на единый портал (с возвратом)
        Auth::requireLogin();
        Auth::home();
    }
}