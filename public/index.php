<?php
// public/index.php

// Permitir peticiones desde el servidor de desarrollo de Vite (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require __DIR__ . '/../vendor/autoload.php';

$f3 = \Base::instance();
$f3->set('AUTOLOAD', '../app/controllers/|../app/services/');

// Cargar variables globales y rutas
$f3->config('../app/config/globals.ini');
$f3->config('../app/config/routes.ini');

$f3->run();