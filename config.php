<?php
$host = 'localhost';
$db = 'cage_cricket';
$user = 'root';
$pass = ''; 

// Create connection
$conn = mysqli_connect($host, $user, $pass, $db);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>