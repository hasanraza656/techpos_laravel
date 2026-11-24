<?php

return [
    // base64:<32 random bytes> from `php artisan techpos:backup-key`. Keep it OUTSIDE the server
    // too (password manager); without it no backup can be decrypted. Never reuse APP_KEY.
    'key' => env('BACKUP_KEY'),

    // Filesystem disk + folder that receives encrypted archives. For a real off-site copy point
    // this at a disk on another machine or provider (see config/filesystems.php).
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups'),

    // Newest archives kept on the backup disk; older ones are pruned after each run.
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Daily scheduler time (server timezone) when BACKUP_KEY is set.
    'time' => env('BACKUP_TIME', '02:00'),

    // Private folders on the `local` disk that hold business files (payment proofs, photos, logo).
    'directories' => ['private'],

    // MySQL/MariaDB client binaries (full path on Windows, e.g. C:\xampp\mysql\bin\mysqldump.exe).
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
];
