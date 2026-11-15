<?php


function print_some_text($a = array("This is just some random text!")){
global $content;

if(is_array($a)){
foreach ($a as $i) {
$content .= $i."</br>";
}
}else{
$content .= $a."</br>";
}


}