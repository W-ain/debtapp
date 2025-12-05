<?php
require_once '../config.php';

$google_auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id'     => '887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com',
    'redirect_uri'  => 'http://localhost/debtapp/login/google_callback.php',
    'response_type' => 'code',
    'scope'         => 'email profile',
    'access_type'   => 'offline',
    'prompt'        => 'select_account'
]);

// Googleログインページへリダイレクト
header("Location: $google_auth_url");
exit;
