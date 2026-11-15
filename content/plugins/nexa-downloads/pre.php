<?php
// Nexa Downloads — pre stage: prepare/request/deliver must run before output.
add_action('pre', 'nexa_dl_handle');
