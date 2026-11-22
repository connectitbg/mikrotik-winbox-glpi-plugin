<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('This endpoint has moved. Update the MikroTik Tools for GLPI client integration.');
