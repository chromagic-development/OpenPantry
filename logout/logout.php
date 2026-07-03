<?php
// Clear the admin cookie and bounce back to the dashboard.
require_once __DIR__ . '/../auth.php';
fpClearAuthCookie();
header('Location: ../');
