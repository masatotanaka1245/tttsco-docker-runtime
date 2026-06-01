<?php
// debug_hash.php
$pass = 'admin123';
$hash = password_hash($pass, PASSWORD_DEFAULT);

echo "Password: " . $pass . "<br>";
echo "Generated Hash: " . $hash . "<br><br>";
echo "Copy the hash below and update your DB:<br>";
echo "<input style='width:400px;' value='" . $hash . "'>";