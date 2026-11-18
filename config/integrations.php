<?php
// config/integrations.php
define('STRIPE_SECRET_KEY', 'local_mock_key');
define('STRIPE_BASE_URL', 'http://127.0.0.1:8082/v1');

define('LOGISTICS_API_URL', 'http://127.0.0.1:8082/v1/waybills');
define('LOGISTICS_API_KEY', 'ELLIANA_LOGISTICS_SECRET_2026');

define('INTEGRATION_LOG_FILE', __DIR__ . '/../logs/integration.log');