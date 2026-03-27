<?php

// Migration: create_users_table

up(function () {
    db()->statement("
        CREATE TABLE IF NOT EXISTS `table_name` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
});

down(function () {
    db()->statement('DROP TABLE IF EXISTS `table_name`');
});
