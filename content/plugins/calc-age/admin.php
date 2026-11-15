<?php
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'calc-age','class'=>(($_GET['plugin_page']??'')==='calc-age')?'active':'','icon'=>'fa fa-calculator fa-fw','label'=>' Calc Age','link'=>'admin.php?plugin_page=calc-age'], 66);
add_action('nexa_admin','calc_age_admin_render');
function calc_age_admin_render(): void { static $d=false; if($d) return; if(($_GET['plugin_page']??'')!=='calc-age') return; $d=true; $out='';
 if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ $age=(int)date('Y')-(int)$_POST['year']; if((int)date('m')<(int)$_POST['month']||((int)date('m')===(int)$_POST['month']&&(int)date('d')<(int)$_POST['day'])) $age--; $out='<div class="alert alert-success">Age: <strong>'.$age.'</strong></div>'; }
 echo '<h2><i class="fa fa-calculator"></i> Calc Age</h2><p>Shortcode: <code>[calc-age day="15" month="06" year="1995"]</code></p>'.$out.'<form method="post" class="form-inline">'.nexa_csrf_field().'<input name="day" type="number" class="form-control" placeholder="Day" required> <input name="month" type="number" class="form-control" placeholder="Month" required> <input name="year" type="number" class="form-control" placeholder="Year" required> <button class="btn btn-primary">Test Calculator</button></form>'; }
