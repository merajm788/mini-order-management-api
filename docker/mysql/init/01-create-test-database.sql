-- The application user needs a second schema for the PHPUnit suite, which runs
-- against MySQL (RefreshDatabase truncates it between tests).
CREATE DATABASE IF NOT EXISTS mini_order_management_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON mini_order_management_test.* TO 'laravel'@'%';
FLUSH PRIVILEGES;
