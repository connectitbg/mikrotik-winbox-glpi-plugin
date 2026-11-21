<?php
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
header('Location: ../Launch?id=' . $id, true, 302);
exit;
