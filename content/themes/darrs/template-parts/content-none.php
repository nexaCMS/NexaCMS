<?php 
global $post,$page;
//echo $page; 
print_r($post);
//print_r($post);
	if(!isset($post) && $page == $settings['page_for_posts']['value']){
		$post = no_posts();
	}
if(!isset($post)){ $post = get_404();}
?>

		<div id="blog">
		        <h1><?=$post['post_title'];?></h1>
                        <p><?=$post['post_content'];?></p>


		</div>