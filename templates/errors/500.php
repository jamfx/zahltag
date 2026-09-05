<?php
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>500 – Serverfehler</title>
<style>body{font-family:system-ui,sans-serif;text-align:center;padding:4rem;color:#333}h1{color:#b91c1c}</style>
</head>
<body>
<h1>500</h1>
<p>Ein unerwarteter Fehler ist aufgetreten.</p>
<p>An unexpected error occurred.</p>
<?php $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'); ?>
<a href="<?= htmlspecialchars($base ?: '/', ENT_QUOTES, 'UTF-8') ?>/">← Startseite / Home</a>
</body>
</html>
