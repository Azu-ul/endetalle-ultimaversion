#!/bin/bash
cd /home/claude/testrun/endetalle-app/public
exec php -S 127.0.0.1:8081 -t /home/claude/testrun/endetalle-app/public /home/claude/testrun/router.php
