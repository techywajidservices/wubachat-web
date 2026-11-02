<?php
// config/db.php

$host = 'localhost';
$db_name = 'u670020116_wubachat_db';     // Apna exact DB Name daalein
$username = 'u670020116_wubachat_user';    // Apna exact DB Username daalein
$password = 'Wubachat@696095';    // Jo password DB banate waqt set kiya tha

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db_name};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}