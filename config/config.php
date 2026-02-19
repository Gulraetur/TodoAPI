<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'Todo';
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "DB conn failed: " . $conn->connect_error]));
}