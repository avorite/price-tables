<?php

defined( 'ABSPATH' ) || exit;

class WVPT_Price_Tables {
	const POST_TYPE        = 'wvpt_price_table';
	const LEGACY_OPTION    = 'wvpt_price_tables';
	const META_ENABLED     = '_wvpt_enabled';
	const META_CATEGORY_IDS = '_wvpt_category_ids';
	const MIGRATED_OPTION  = 'wvpt_posts_migrated';

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'               => 'Price Tables',
					'singular_name'      => 'Price Table',
					'add_new_item'       => 'Add New Price Table',
					'edit_item'          => 'Edit Price Table',
					'new_item'           => 'New Price Table',
					'view_item'          => 'View Price Table',
					'search_items'       => 'Search Price Tables',
					'not_found'          => 'No Price Tables found',
					'not_found_in_trash' => 'No Price Tables found in Trash',
					'menu_name'          => 'Price Tables',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'menu_position'   => 56,
			)
		);
	}

	public static function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'wvpt-admin', WVPT_PLUGIN_URL . 'assets/css/admin.css', array(), WVPT_VERSION );
	}

	public static function enqueue_frontend_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_style( 'wvpt-frontend', WVPT_PLUGIN_URL . 'assets/css/frontend.css', array(), WVPT_VERSION );
	}

	public static function maybe_seed_tables() {
		if ( ! post_type_exists( self::POST_TYPE ) || get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		if ( $existing ) {
			update_option( self::MIGRATED_OPTION, 'yes', false );
			return;
		}

		$legacy_tables = get_option( self::LEGACY_OPTION, array() );
		$tables        = is_array( $legacy_tables ) && $legacy_tables ? $legacy_tables : self::default_tables();

		foreach ( $tables as $table ) {
			self::insert_table_post( $table );
		}

		update_option( self::MIGRATED_OPTION, 'yes', false );
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'wvpt_settings',
			'Price Table Settings',
			array( __CLASS__, 'render_settings_metabox' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render_settings_metabox( $post ) {
		$enabled      = get_post_meta( $post->ID, self::META_ENABLED, true );
		$category_ids = self::get_post_category_ids( $post->ID );
		$terms        = self::get_product_categories_for_select();

		wp_nonce_field( 'wvpt_save_table', 'wvpt_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="wvpt_enabled" value="yes" <?php checked( 'yes' === $enabled || '' === $enabled ); ?>>
				Show on site
			</label>
		</p>
		<p><strong>Categories</strong></p>
		<select class="wvpt-categories" name="wvpt_category_ids[]" multiple size="14">
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $category_ids, true ) ); ?>><?php echo esc_html( $term->label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">Hold Ctrl/Cmd to select multiple categories. Products in child categories also inherit parent table matches.</p>
		<?php
	}

	public static function save_post( $post_id ) {
		if ( ! isset( $_POST['wvpt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvpt_nonce'] ) ), 'wvpt_save_table' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_ENABLED, isset( $_POST['wvpt_enabled'] ) && 'yes' === $_POST['wvpt_enabled'] ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_CATEGORY_IDS, self::sanitize_category_ids( isset( $_POST['wvpt_category_ids'] ) ? (array) wp_unslash( $_POST['wvpt_category_ids'] ) : array() ) );
	}

	public static function admin_columns( $columns ) {
		$columns['wvpt_enabled']    = 'Shown';
		$columns['wvpt_categories'] = 'Categories';

		return $columns;
	}

	public static function render_admin_column( $column, $post_id ) {
		if ( 'wvpt_enabled' === $column ) {
			echo esc_html( 'yes' === get_post_meta( $post_id, self::META_ENABLED, true ) ? 'Yes' : 'No' );
			return;
		}

		if ( 'wvpt_categories' === $column ) {
			$names = array();
			foreach ( self::get_post_category_ids( $post_id ) as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->name;
				}
			}
			echo esc_html( $names ? implode( ', ', $names ) : '-' );
		}
	}

	public static function render_product_tables() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$matched = self::get_matching_tables_for_product( get_queried_object_id() );

		if ( empty( $matched ) ) {
			return;
		}

		echo '<section class="wvpt-product-price-tables">';
		echo '<h2>Ціни на нанесення</h2>';

		foreach ( $matched as $post ) {
			echo '<article class="wvpt-product-price-table">';
			echo '<h3>' . esc_html( get_the_title( $post ) ) . '</h3>';
			echo '<div class="wvpt-price-table-scroll">' . self::sanitize_table_html( apply_filters( 'the_content', $post->post_content ) ) . '</div>';
			echo '</article>';
		}

		echo '</section>';
	}

	private static function get_matching_tables_for_product( $product_id ) {
		$product_category_ids = self::get_product_category_ids_with_ancestors( $product_id );

		if ( empty( $product_category_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_ENABLED,
						'value' => 'yes',
					),
				),
			)
		);

		return array_values(
			array_filter(
				$posts,
				function ( $post ) use ( $product_category_ids ) {
					return (bool) array_intersect( $product_category_ids, self::get_post_category_ids( $post->ID ) );
				}
			)
		);
	}

	private static function insert_table_post( $table ) {
		$title = isset( $table['title'] ) ? sanitize_text_field( $table['title'] ) : 'Price Table';
		$html  = isset( $table['html'] ) ? self::sanitize_table_html( $table['html'] ) : '';

		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return 0;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $html,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_ENABLED, isset( $table['enabled'] ) ? $table['enabled'] : 'yes' );
		update_post_meta( $post_id, self::META_CATEGORY_IDS, self::sanitize_category_ids( isset( $table['category_ids'] ) ? (array) $table['category_ids'] : array() ) );

		return (int) $post_id;
	}

	private static function default_tables() {
		return array(
			self::default_table( 'УФ друк: ручки, олівці, флешки', 'pens-pencils-flash.html', array( 'metaleva-ruchka', 'plastikova-ruchka', 'eko-ruchka', 'druk-na-ruchkah', 'brenduvannya-ruchok' ) ),
			self::default_table( 'УФ друк: пляшки, термоси, чашки', 'bottles-thermos-cups.html', array( 'druk-na-plyashkah', 'druk-na-termokruzhkah', 'druk-na-termosah' ) ),
			self::default_table( 'Брендування одягу', 'clothing-branding.html', array( 'druk-na-zhyletakh', 'druk-na-svitshotakh', 'druk-na-tolstovkakh-khudi' ) ),
			self::default_table( 'Брендування кепок', 'caps-branding.html', array( 'druk-na-kepkakh' ) ),
			self::default_table( 'Блокноти, щоденники, павербанки', 'notebooks-diaries-powerbanks.html', array( 'brenduvannya-shhodennikiv-bloknotiv', 'brenduvannya-bloknotiv', 'brenduvannya-godynnykiv', 'brenduvannya-zaryadnyh-prystroyiv' ) ),
		);
	}

	private static function default_table( $title, $file, $category_slugs ) {
		return array(
			'title'        => $title,
			'enabled'      => 'yes',
			'category_ids' => self::resolve_category_ids_by_slugs( $category_slugs ),
			'html'         => self::read_template_file( $file ),
		);
	}

	private static function read_template_file( $file ) {
		$path = WVPT_PLUGIN_DIR . 'assets/price-tables/' . sanitize_file_name( $file );

		if ( ! is_readable( $path ) ) {
			return '';
		}

		return self::sanitize_table_html( (string) file_get_contents( $path ) );
	}

	private static function sanitize_table_html( $html ) {
		$html = str_replace( 'wti-price-table', 'wvpt-price-table', (string) $html );
		$allowed = array(
			'table' => array( 'class' => true ),
			'thead' => array(),
			'tbody' => array(),
			'tfoot' => array(),
			'tr'    => array( 'class' => true ),
			'th'    => array( 'class' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true ),
			'td'    => array( 'class' => true, 'colspan' => true, 'rowspan' => true ),
			'br'    => array(),
			'p'     => array(),
			'strong' => array(),
			'b'     => array(),
			'em'    => array(),
			'i'     => array(),
			'span'  => array( 'class' => true ),
		);

		return wp_kses( $html, $allowed );
	}

	private static function get_post_category_ids( $post_id ) {
		return self::sanitize_category_ids( get_post_meta( $post_id, self::META_CATEGORY_IDS, true ) );
	}

	private static function get_product_category_ids_with_ancestors( $product_id ) {
		$ids   = array();
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		foreach ( $terms as $term_id ) {
			$term_id = absint( $term_id );
			$ids[]   = $term_id;
			$ids     = array_merge( $ids, array_map( 'absint', get_ancestors( $term_id, 'product_cat' ) ) );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function sanitize_category_ids( $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	private static function resolve_category_ids_by_slugs( $slugs ) {
		$ids = array();

		foreach ( $slugs as $slug ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'slug'       => sanitize_title( $slug ),
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( function_exists( 'pll_get_term_language' ) && 'uk' !== pll_get_term_language( $term->term_id ) ) {
					continue;
				}

				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function get_product_categories_for_select() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$by_parent = array();

		foreach ( $terms as $term ) {
			if ( function_exists( 'pll_get_term_language' ) && 'uk' !== pll_get_term_language( $term->term_id ) ) {
				continue;
			}

			$by_parent[ (int) $term->parent ][] = $term;
		}

		$sorted = array();
		self::append_terms( 0, $by_parent, $sorted, 0 );

		return $sorted;
	}

	private static function append_terms( $parent_id, $by_parent, &$sorted, $depth ) {
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return;
		}

		usort(
			$by_parent[ $parent_id ],
			function ( $left, $right ) {
				return strnatcasecmp( $left->name, $right->name );
			}
		);

		foreach ( $by_parent[ $parent_id ] as $term ) {
			$term->label = str_repeat( '- ', $depth ) . $term->name;
			$sorted[]    = $term;
			self::append_terms( (int) $term->term_id, $by_parent, $sorted, $depth + 1 );
		}
	}
}
