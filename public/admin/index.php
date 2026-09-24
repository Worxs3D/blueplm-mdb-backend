<?php
declare(strict_types=1);

header_remove('X-Powered-By');
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'NOT_FOUND',
    'message' => 'Administration is available in the BluePLM desktop app.',
]);
