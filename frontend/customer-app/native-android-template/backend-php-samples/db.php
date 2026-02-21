<?php
// backend-php-samples/db.php

$pdo = new PDO(
  'mysql:host=127.0.0.1;dbname=taxi_app;charset=utf8mb4',
  'root',
  'password',
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]
);
header('Content-Type: application/json');
