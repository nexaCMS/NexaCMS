	
	<div class="features">
		<div class="container">
			<div class="text-center">
				<div class="wow bounceInDown" data-wow-offset="0" data-wow-delay="0.3s">
		<?php
		if ( have_posts() ) :

			if ( is_home() && ! is_front_page() ) : ?>
				<header>
										<h2><?php single_post_title(); ?></h2>
				</header>

			<?php
			endif;

			/* Start the Loop */
			while ( have_posts() ) : the_post();

				/*
				 * Include the Post-Format-specific template for the content.
				 * If you want to override this in a child theme, then include a file
				 * called content-___.php (where ___ is the Post Format name) and that will be used instead.
				 */
				//get_template_part( 'template-parts/content', get_post_format() );

			endwhile;

			the_posts_navigation();

		else :

			//get_template_part( 'template-parts/content', 'none' );

		endif; ?>
 

				</div>
				<div class="wow bounceInDown" data-wow-offset="0" data-wow-delay="0.6s">
					<p>Bras urna felis accumsan at ultrde cesid posuere masa socis nautoque<br>
					penat bus maecenas ultrices sed ipsum lorem
					dolor sit amet sed ipsum consectetur<br></p>
				</div>
			</div>
		</div>
	</div>
	

	<footer>
		<div class="footer">
			<div class="container">
				<div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.2s">
					<h2>Multi</h2>
					<p>In a elit in lorem congue varius. Sed nec arcu.
					Etiam sit amet augue.
					Fusce fermen tum neque a rutrum varius odio pede 
					ullamcorp-er tellus ut dignissim nisi risus non tortor.
					Aliquam mollis neque.</p>
					
					<ul class="social-network">
						<li><a href="#" data-placement="top" title="Facebook"><i class="fa fa-facebook fa-1x"></i></a></li>
						<li><a href="#" data-placement="top" title="Twitter"><i class="fa fa-twitter fa-1x"></i></a></li>
						<li><a href="#" data-placement="top" title="Linkedin"><i class="fa fa-linkedin fa-1x"></i></a></li>
						<li><a href="#" data-placement="top" title="Pinterest"><i class="fa fa-pinterest fa-1x"></i></a></li>
						<li><a href="#" data-placement="top" title="Google plus"><i class="fa fa-google-plus fa-1x"></i></a></li>
					</ul>
				</div>
				
				<div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.4s">
					<h3>RECENT POSTS</h3>
					<ul>
						<li>Awesome Design with Overviews</li><hr>
						<li>Great Design with Features </li><hr>
						<li>Limitless functions & Works </li><hr>
						<li>Multi is simple and clean design</li>
					</ul>
				</div>
				
				<div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.6s">
					<h3>CONTACT INFO</h3>
					<ul>
						<li><i class="fa fa-home fa-2x"></i> Office # 38, Suite 54 Elizebth Street, Victoria State Newyork, USA 33026</li><hr>
						<li><i class="fa fa-phone fa-2x"></i> +38 000 129900</li><hr>
						<li><i class="fa fa-envelope fa-2x"></i> info@domain.net</li>
					</ul>
				</div>
				
			</div>
		</div>
		
		<div class="sub-footer">
			<div class="container">
				<div class="row">
					<div class="col-md-6">
						&copy; 2015 <a target="_blank" href="http://bootstraptaste.com/" title="Free Twitter Bootstrap WordPress Themes and HTML templates">bootstraptaste</a>. All Rights Reserved.
					</div>
                    <!-- 
                        All links in the footer should remain intact. 
                        Licenseing information is available at: http://bootstraptaste.com/license/
                        You can buy this theme without footer links online at: http://bootstraptaste.com/buy/?theme=Multi
                    -->
					<div class="col-md-6">
						<ul class="pull-right">
							<li><a href="<?=$td?>/index.html">Home</a></li>
							<li><a href="#">Services</a></li>
							<li><a href="#">Blog</a></li>
							<li><a href="#">Portfolio</a></li>
							<li><a href="#">Contact</a></li>
						</ul>
					</div>
				</div>
				<div class="pull-right">
					<a href="#home" class="scrollup"><i class="fa fa-angle-up fa-3x"></i></a>
				</div>
			</div>
		</div>		
	</footer>
   
	<?php up_post();?>
    
  </body>
</html>