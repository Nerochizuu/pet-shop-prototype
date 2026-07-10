<?php
/**
 * Database connection
 * PawPriority / Mago PetCare - Inventory Module
 */

// ── Update these to match your environment ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'pawpriority_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}