// Server configuration for the JS modules (area/GLN/coordinates from .env, never from user input).
// index.php embeds it as a JSON data block, which a Content-Security-Policy without
// 'unsafe-inline' allows (it is data, not script); this reads it into window.sparkConfig.
window.sparkConfig = JSON.parse(document.getElementById('spark-config')?.textContent || '{}');
