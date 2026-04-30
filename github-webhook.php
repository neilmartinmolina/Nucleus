<?php
file_put_contents("log.txt", file_get_contents("php://input") . "\n\n", FILE_APPEND);
echo "ok";