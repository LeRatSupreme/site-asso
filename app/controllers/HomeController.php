<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;
use App\Models\Setting;

/**
 * Page d'accueil publique.
 */
final class HomeController extends Controller
{
    public function index(): void
    {
        $this->render('home', [
            'title'           => Setting::get('site_name', 'AEIC'),
            'description'     => Setting::get('site_description'),
            'siteName'        => Setting::get('site_name', 'AEIC'),
            'events'          => Event::upcoming(3),
            'maintenanceMode' => Setting::getBool('maintenance_mode', false),
        ]);
    }
}
