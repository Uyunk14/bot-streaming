<?php
// Halaman depan: arahkan ke Mini App (atau pemasangan bila belum terpasang).
header('Location: ' . (is_file(__DIR__ . '/config.php') ? 'app/' : 'install.php'));
