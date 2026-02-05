<?php
require("../inc/connection.php");

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

$db = dbconnect();

$stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// echo $user['username'];
if ($user['username'] == $username && $password == $user['password']) {
		session_start();
		$_SESSION['user'] = $user;
	header("Location: home.php");
	exit;
} else {
	header("Location: login.php?error=1");
	exit;
}
