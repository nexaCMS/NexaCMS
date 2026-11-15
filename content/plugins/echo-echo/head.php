<?php
$args = array("I like dogs", "I hate cats", "The sky is blue", "wow");

addHook("up_pre", "print_some_text", $args, 200);