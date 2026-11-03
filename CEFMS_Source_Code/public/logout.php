<?php
require __DIR__ . '/../includes/bootstrap.php';
logout_user();
session_start(); flash('You have been logged out.');
redirect('/');
