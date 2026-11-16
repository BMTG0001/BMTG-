<?php
namespace BMTG\PrivateJourney\Admin;

use BMTG\PrivateJourney\Audit\Audit;
use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Brand;
use BMTG\PrivateJourney\Core\Capabilities;
use BMTG\PrivateJourney\Core\Pages;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Core\Settings;
use BMTG\PrivateJourney\Documents\Documents;
use BMTG\PrivateJourney\Enquiry\Enquiry;
use BMTG\PrivateJourney\Enquiry\Quotation;
use BMTG\PrivateJourney\Pricing\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff administration: dashboard, catalogue, suppliers, requests,
 * quotations, clients, documents, reports and settings.
 */
class Admin {
	const MENU = 'bmtg-private-journey';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'admin_post_bmtg_pj_admin_save_item', array( $this, 'save_item' ) );
		add_action( 'admin_post_bmtg_pj_admin_delete_item', array( $this, 'delete_item' ) );
		add_action( 'admin_post_bmtg_pj_admin_save_supplier', array( $this, 'save_supplier' ) );
		add_action( 'admin_post_bmtg_pj_admin_delete_supplier', array( $this, 'delete_supplier' ) );
		add_action( 'admin_post_bmtg_pj_admin_update_request', array( $this, 'update_request' ) );
		add_action( 'admin_post_bmtg_pj_admin_quote_save', array( $this, 'save_quote' ) );
		add_action( 'admin_post_bmtg_pj_admin_quote_issue', array( $this, 'issue_quote' ) );
		add_action( 'admin_post_bmtg_pj_admin_upload_doc', array( $this, 'upload_doc' ) );
		add_action( 'admin_post_bmtg_pj_admin_delete_doc', array( $this, 'delete_doc' ) );
		add_action( 'admin_post_bmtg_pj_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_bmtg_pj_pages', array( $this, 'save_pages' ) );
		add_action( 'admin_post_bmtg_pj_create_pages', array( $this, 'create_pages' ) );
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::MENU ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'bmtg-pj-admin', BMTG_PJ_URL . 'assets/css/admin.css', array(), BMTG_PJ_VERSION );
		wp_enqueue_script( 'bmtg-pj-admin', BMTG_PJ_URL . 'assets/js/admin.js', array(), BMTG_PJ_VERSION, true );
	}

	private function can( string $cap ): void {
		if ( ! Capabilities::can_manage() && ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Private Journey.', 'bmtg-private-journey' ), '', array( 'response' => 403 ) );
		}
	}

	private function verify( string $action ): void {
		$nonce = '';
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		} elseif ( isset( $_GET['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		}
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'bmtg-private-journey' ), '', array( 'response' => 403 ) );
		}
	}

	private function redirect( string $page, array $args = array() ): void {
		$url = add_query_arg(
			array_merge(
				array( 'page' => $page ),
				$args
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function notice(): void {
		if ( isset( $_GET['pj_notice'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['pj_notice'] ) ) ) );
		}
		if ( isset( $_GET['pj_error'] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['pj_error'] ) ) ) );
		}
	}

	private function page( string $title, string $body, string $subtitle = '' ): void {
		echo '<div class="wrap pj-admin">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="description">' . esc_html( $subtitle ) . '</p>';
		}
		$this->notice();
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	private function form_open( string $action, array $hidden = array() ): void {
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	private function form_close( string $label = 'Save changes' ): void {
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
		echo '</form>';
	}

	private function field( string $label, string $input, string $help = '', bool $wide = false ): void {
		echo '<p class="' . ( $wide ? 'pj-field-wide' : '' ) . '"><label><strong>' . esc_html( $label ) . '</strong></label>' . $input . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	private function input( string $name, string $value = '', string $type = 'text', array $attrs = array() ): string {
		$attr = '';
		foreach ( $attrs as $key => $val ) {
			$attr .= ' ' . $key . '="' . esc_attr( $val ) . '"';
		}
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $attr . ' />';
	}

	private function textarea( string $name, string $value = '', int $rows = 4 ): string {
		return '<textarea name="' . esc_attr( $name ) . '" rows="' . esc_attr( $rows ) . '" class="large-text">' . esc_textarea( $value ) . '</textarea>';
	}

	private function select( string $name, array $options, string $selected = '' ): string {
		$out = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out . '</select>';
	}

	private function link( string $page, string $label, array $args = array(), string $class = 'button' ): string {
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	private function delete_link( string $action, int $id, string $page, string $label = 'Delete' ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'id'     => $id,
					'page'   => $page,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $id
		);
		return '<a class="button-link-delete" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private function paginate( int $total, int $per_page, int $page, string $page_slug, array $args = array() ): string {
		$pages = (int) max( 1, ceil( $total / $per_page ) );
		if ( $pages < 2 ) {
			return '';
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'bmtg-private-journey' ), $page, $pages ) ) . '</span>';
		for ( $i = 1; $i <= $pages; $i++ ) {
			echo '<a class="button ' . ( $i === $page ? 'button-primary' : '' ) . '" href="' . esc_url( add_query_arg( array_merge( $args, array( 'page' => $page_slug, 'paged' => $i ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $i ) . '</a> ';
		}
		echo '</div></div>';
		return '';
	}

	/* ----------------------------------------------------------------- Menu */

	public function menu(): void {
		add_menu_page(
			__( 'Private Journey', 'bmtg-private-journey' ),
			__( 'Private Journey', 'bmtg-private-journey' ),
			Capabilities::VIEW_REQUESTS,
			self::MENU,
			array( $this, 'page_dashboard' ),
			'dashicons-palmtree',
			58
		);

		$subs = array(
			self::MENU . '-catalogue' => array( __( 'Catalogue', 'bmtg-private-journey' ), Capabilities::MANAGE_CATALOG, 'page_catalogue' ),
			self::MENU . '-suppliers' => array( __( 'Suppliers', 'bmtg-private-journey' ), Capabilities::MANAGE_SUPPLIERS, 'page_suppliers' ),
			self::MENU . '-requests'  => array( __( 'Journey Requests', 'bmtg-private-journey' ), Capabilities::VIEW_REQUESTS, 'page_requests' ),
			self::MENU . '-quotes'    => array( __( 'Quotations', 'bmtg-private-journey' ), Capabilities::MANAGE_QUOTES, 'page_quotes' ),
			self::MENU . '-clients'   => array( __( 'Clients', 'bmtg-private-journey' ), Capabilities::VIEW_REQUESTS, 'page_clients' ),
			self::MENU . '-docs'      => array( __( 'Documents', 'bmtg-private-journey' ), Capabilities::MANAGE_DOCS, 'page_documents' ),
			self::MENU . '-reports'   => array( __( 'Reports', 'bmtg-private-journey' ), Capabilities::VIEW_REPORTS, 'page_reports' ),
			self::MENU . '-settings'  => array( __( 'Settings', 'bmtg-private-journey' ), Capabilities::MANAGE_SETTINGS, 'page_settings' ),
		);

		foreach ( $subs as $slug => $meta ) {
			add_submenu_page(
				self::MENU,
				$meta[0],
				$meta[0],
				$meta[1],
				$slug,
				array( $this, $meta[2] )
			);
		}
	}

	/* ------------------------------------------------------------ Dashboard */

	public function page_dashboard(): void {
		$this->can( Capabilities::VIEW_REQUESTS );
		global $wpdb;
		$enq   = Schema::enquiries();
		$cli   = Schema::clients();
		$quote = Schema::quotation_versions();
		$conf  = Schema::confirmed_journeys();
		$items = Schema::catalogue_items();

		$counts = array(
			__( 'Clients', 'bmtg-private-journey' )        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cli" ),
			__( 'Enquiries', 'bmtg-private-journey' )      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $enq" ),
			__( 'Published services', 'bmtg-private-journey' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $items WHERE status = 'published'" ),
			__( 'Quotations issued', 'bmtg-private-journey' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quote WHERE status = 'issued'" ),
			__( 'Confirmed journeys', 'bmtg-private-journey' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $conf" ),
		);

		ob_start();
		echo '<div class="pj-cards">';
		foreach ( $counts as $label => $count ) {
			echo '<div class="pj-card-stat"><strong>' . esc_html( $count ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
		}
		echo '</div>';

		$missing = array();
		foreach ( Pages::all() as $key => $meta ) {
			if ( ! $meta['page_id'] ) {
				$missing[] = $meta['label'];
			}
		}
		echo '<h2>' . esc_html__( 'Setup checklist', 'bmtg-private-journey' ) . '</h2><ul>';
		if ( $missing ) {
			echo '<li>' . esc_html( sprintf( __( 'Create mapped pages for: %s', 'bmtg-private-journey' ), implode( ', ', $missing ) ) ) . ' ' . $this->link( self::MENU . '-settings', __( 'Open page mapping', 'bmtg-private-journey' ), array( 'tab' => 'pages' ) ) . '</li>';
		} else {
			echo '<li>' . esc_html__( 'All plugin pages are mapped.', 'bmtg-private-journey' ) . '</li>';
		}
		$recipients = Settings::email_list( 'email', 'internal_recipients' );
		echo '<li>' . ( $recipients ? esc_html__( 'Internal enquiry recipients configured.', 'bmtg-private-journey' ) : esc_html__( 'Configure internal enquiry recipients so new requests are delivered.', 'bmtg-private-journey' ) . ' ' . $this->link( self::MENU . '-settings', __( 'Open email settings', 'bmtg-private-journey' ), array( 'tab' => 'email' ) ) ) . '</li>';
		echo '<li>' . ( Brand::company( 'logo_id' ) ? esc_html__( 'Brand logo configured.', 'bmtg-private-journey' ) : esc_html__( 'Upload your primary logo in Brand & Business Identity.', 'bmtg-private-journey' ) . ' ' . $this->link( self::MENU . '-settings', __( 'Open brand settings', 'bmtg-private-journey' ), array( 'tab' => 'brand' ) ) ) . '</li>';
		echo '</ul>';

		$recent = Enquiry::instance()->list( array( 'per_page' => 8 ) );
		echo '<h2>' . esc_html__( 'Recent journey requests', 'bmtg-private-journey' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Reference', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Client', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Destinations', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Received', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $recent['items'] as $row ) {
			echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => self::MENU . '-requests', 'action' => 'edit', 'id' => $row['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $row['reference'] ) . '</a></td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['destinations'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $row['created_at'] ) ) . '</td></tr>';
		}
		if ( empty( $recent['items'] ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No enquiries yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->page( __( 'Private Journey', 'bmtg-private-journey' ), (string) ob_get_clean(), __( 'A private travel discovery and quotation platform.', 'bmtg-private-journey' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public function page_settings(): void {
		$this->can( Capabilities::MANAGE_SETTINGS );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'brand';
		$tabs = array(
			'brand'  => __( 'Brand & Business Identity', 'bmtg-private-journey' ),
			'social' => __( 'Social & Contact', 'bmtg-private-journey' ),
			'email'  => __( 'Email', 'bmtg-private-journey' ),
			'auth'   => __( 'Access', 'bmtg-private-journey' ),
			'pages'  => __( 'Page Mapping', 'bmtg-private-journey' ),
			'roles'  => __( 'Roles', 'bmtg-private-journey' ),
		);
		$nav = '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$nav .= '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::MENU . '-settings', 'tab' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$nav .= '</h2>';

		$body = '';
		if ( 'pages' === $tab ) {
			$body = $this->settings_pages();
		} elseif ( 'roles' === $tab ) {
			$body = $this->settings_roles();
		} else {
			$body = $this->settings_form( $tab );
		}

		$this->page( __( 'Private Journey Settings', 'bmtg-private-journey' ), $nav . $body, __( 'All values are editable. Nothing is hardcoded.', 'bmtg-private-journey' ) );
	}

	private function settings_form( string $tab ): string {
		$s = Settings::all();
		ob_start();
		$this->form_open( 'bmtg_pj_settings', array( 'tab' => $tab ) );

		if ( 'brand' === $tab ) {
			$c = $s['company'];
			$b = $s['brand'];
			$this->field( __( 'Business name', 'bmtg-private-journey' ), $this->input( 'company[name]', $c['name'] ) );
			$this->field( __( 'Company description', 'bmtg-private-journey' ), $this->textarea( 'company[description]', $c['description'], 3 ), '', true );
			$this->field( __( 'Primary logo', 'bmtg-private-journey' ), $this->media_field( 'company[logo_id]', (int) $c['logo_id'] ) );
			$this->field( __( 'Light logo (optional)', 'bmtg-private-journey' ), $this->media_field( 'company[logo_light_id]', (int) $c['logo_light_id'] ) );
			$this->field( __( 'Dark logo (optional)', 'bmtg-private-journey' ), $this->media_field( 'company[logo_dark_id]', (int) $c['logo_dark_id'] ) );
			$this->field( __( 'Logo display width (px)', 'bmtg-private-journey' ), $this->input( 'company[logo_width]', (string) $c['logo_width'], 'number' ) );
			$this->field( __( 'Registered address', 'bmtg-private-journey' ), $this->textarea( 'company[address_registered]', $c['address_registered'], 3 ), '', true );
			$this->field( __( 'Correspondence address', 'bmtg-private-journey' ), $this->textarea( 'company[address_correspondence]', $c['address_correspondence'], 3 ), '', true );
			$this->field( __( 'Telephone', 'bmtg-private-journey' ), $this->input( 'company[phone]', $c['phone'] ) );
			$this->field( __( 'Official email', 'bmtg-private-journey' ), $this->input( 'company[email]', $c['email'], 'email' ) );
			$this->field( __( 'Website', 'bmtg-private-journey' ), $this->input( 'company[website]', $c['website'], 'url' ) );
			$this->field( __( 'Registration information', 'bmtg-private-journey' ), $this->input( 'company[registration]', $c['registration'] ) );

			echo '<h2>' . esc_html__( 'Brand identity', 'bmtg-private-journey' ) . '</h2>';
			foreach ( array(
				'primary' => __( 'Primary colour', 'bmtg-private-journey' ),
				'secondary' => __( 'Secondary colour', 'bmtg-private-journey' ),
				'accent' => __( 'Antique gold accent', 'bmtg-private-journey' ),
				'background' => __( 'Page background', 'bmtg-private-journey' ),
				'text' => __( 'Text colour', 'bmtg-private-journey' ),
				'text_muted' => __( 'Muted text colour', 'bmtg-private-journey' ),
			) as $key => $label ) {
				$this->field( $label, '<input type="color" name="brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $b[ $key ] ) . '" />' );
			}
			$this->field( __( 'Heading typography', 'bmtg-private-journey' ), $this->input( 'brand[heading_font]', $b['heading_font'] ), __( 'CSS font stack, e.g. Georgia, serif', 'bmtg-private-journey' ) );
			$this->field( __( 'Body typography', 'bmtg-private-journey' ), $this->input( 'brand[body_font]', $b['body_font'] ), '', true );
		}

		if ( 'social' === $tab ) {
			$sc = $s['social'];
			$this->field( __( 'Instagram enabled', 'bmtg-private-journey' ), '<label><input type="checkbox" name="social[instagram_enable]" value="1" ' . checked( ! empty( $sc['instagram_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			$this->field( __( 'Instagram profile URL', 'bmtg-private-journey' ), $this->input( 'social[instagram_url]', $sc['instagram_url'], 'url' ) );
			$this->field( __( 'Facebook enabled', 'bmtg-private-journey' ), '<label><input type="checkbox" name="social[facebook_enable]" value="1" ' . checked( ! empty( $sc['facebook_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			$this->field( __( 'Facebook page URL', 'bmtg-private-journey' ), $this->input( 'social[facebook_url]', $sc['facebook_url'], 'url' ) );
			$this->field( __( 'WhatsApp enabled', 'bmtg-private-journey' ), '<label><input type="checkbox" name="social[whatsapp_enable]" value="1" ' . checked( ! empty( $sc['whatsapp_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			$this->field( __( 'WhatsApp business number', 'bmtg-private-journey' ), $this->input( 'social[whatsapp_number]', $sc['whatsapp_number'] ), __( 'International format, digits only or with +', 'bmtg-private-journey' ) );
			$this->field( __( 'WhatsApp prefilled message', 'bmtg-private-journey' ), $this->textarea( 'social[whatsapp_message]', $sc['whatsapp_message'], 3 ), '', true );
			$this->field( __( 'Contact email link', 'bmtg-private-journey' ), '<label><input type="checkbox" name="social[contact_email_enable]" value="1" ' . checked( ! empty( $sc['contact_email_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label> ' . $this->input( 'social[contact_email]', $sc['contact_email'], 'email' ) );
			$this->field( __( 'Contact phone link', 'bmtg-private-journey' ), '<label><input type="checkbox" name="social[contact_phone_enable]" value="1" ' . checked( ! empty( $sc['contact_phone_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label> ' . $this->input( 'social[contact_phone]', $sc['contact_phone'] ) );
		}

		if ( 'email' === $tab ) {
			$e = $s['email'];
			$this->field( __( 'Internal recipients', 'bmtg-private-journey' ), $this->textarea( 'email[internal_recipients]', $e['internal_recipients'], 3 ), __( 'One address per line or comma separated. New enquiries are sent here only.', 'bmtg-private-journey' ), true );
			$this->field( __( 'Internal CC', 'bmtg-private-journey' ), $this->textarea( 'email[internal_cc]', $e['internal_cc'], 2 ), '', true );
			$this->field( __( 'From name', 'bmtg-private-journey' ), $this->input( 'email[from_name]', $e['from_name'] ) );
			$this->field( __( 'From email', 'bmtg-private-journey' ), $this->input( 'email[from_email]', $e['from_email'], 'email' ) );
			$this->field( __( 'Use client email as Reply-To', 'bmtg-private-journey' ), '<label><input type="checkbox" name="email[reply_to_client]" value="1" ' . checked( ! empty( $e['reply_to_client'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The client is never placed in To, CC or BCC. No automatic client acknowledgement is sent.', 'bmtg-private-journey' ) . '</p></div>';
		}

		if ( 'auth' === $tab ) {
			$a = $s['auth'];
			$this->field( __( 'Registration enabled', 'bmtg-private-journey' ), '<label><input type="checkbox" name="auth[registration_enable]" value="1" ' . checked( ! empty( $a['registration_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			$this->field( __( 'After login go to', 'bmtg-private-journey' ), $this->select( 'auth[login_redirect]', array( 'dashboard' => __( 'Client dashboard', 'bmtg-private-journey' ), 'catalogue' => __( 'Catalogue', 'bmtg-private-journey' ) ), $a['login_redirect'] ) );
			$this->field( __( 'After logout go to', 'bmtg-private-journey' ), $this->select( 'auth[logout_redirect]', array( 'home' => __( 'Public website', 'bmtg-private-journey' ), 'login' => __( 'Sign-in page', 'bmtg-private-journey' ) ), $a['logout_redirect'] ) );
			$this->field( __( 'Google enabled', 'bmtg-private-journey' ), '<label><input type="checkbox" name="auth[google_enable]" value="1" ' . checked( ! empty( $a['google_enable'] ), true, false ) . ' /> ' . esc_html__( 'Enable', 'bmtg-private-journey' ) . '</label>' );
			$this->field( __( 'Google OAuth client ID', 'bmtg-private-journey' ), $this->input( 'auth[google_client_id]', $a['google_client_id'] ), __( 'Use the OAuth web client ID. Signing in is verified server-side against Google.', 'bmtg-private-journey' ), true );
			$this->field( __( 'Google OAuth client secret', 'bmtg-private-journey' ), $this->input( 'auth[google_client_secret]', $a['google_client_secret'], 'password' ), __( 'Optional; not required for ID token verification.', 'bmtg-private-journey' ), true );
		}

		$this->form_close( __( 'Save settings', 'bmtg-private-journey' ) );
		return (string) ob_get_clean();
	}

	private function media_field( string $name, int $attachment_id ): string {
		$url = $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		return '<div class="pj-media-field" data-pj-media="' . esc_attr( $name ) . '">'
			. '<img src="' . esc_url( $url ) . '" style="max-width:180px;height:auto;display:block;margin-bottom:6px;" alt="" />'
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $attachment_id ) . '" />'
			. '<button type="button" class="button pj-media-select">' . esc_html__( 'Select image', 'bmtg-private-journey' ) . '</button> '
			. '<button type="button" class="button pj-media-clear">' . esc_html__( 'Clear', 'bmtg-private-journey' ) . '</button>'
			. '</div>';
	}

	private function settings_pages(): string {
		$all = Pages::all();
		ob_start();
		echo '<h2>' . esc_html__( 'Map plugin screens to WordPress pages', 'bmtg-private-journey' ) . '</h2>';
		echo '<p>' . esc_html__( 'Assign an existing page to each screen, or create the pages with the correct shortcode. Pages are only created when you explicitly choose to.', 'bmtg-private-journey' ) . '</p>';
		$this->form_open( 'bmtg_pj_pages' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Screen', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Page', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Shortcode', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Route', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $all as $key => $meta ) {
			$pages = get_pages( array( 'sort_column' => 'post_title' ) );
			echo '<tr><td><strong>' . esc_html( $meta['label'] ) . '</strong></td><td><select name="pages[' . esc_attr( $key ) . ']"><option value="0">' . esc_html__( '— Not mapped —', 'bmtg-private-journey' ) . '</option>';
			foreach ( $pages as $p ) {
				echo '<option value="' . esc_attr( $p->ID ) . '" ' . selected( (int) $meta['page_id'], (int) $p->ID, false ) . '>' . esc_html( $p->post_title ) . '</option>';
			}
			echo '</select></td><td><code>[' . esc_html( $meta['shortcode'] ) . ']</code></td><td><code>/' . esc_html( $meta['slug'] ) . '/</code></td></tr>';
		}
		echo '</tbody></table>';
		$this->form_close( __( 'Save page mapping', 'bmtg-private-journey' ) );

		echo '<h2>' . esc_html__( 'Create missing pages', 'bmtg-private-journey' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates only pages that do not yet exist, each containing its shortcode. Repeated use never duplicates pages.', 'bmtg-private-journey' ) . '</p>';
		$this->form_open( 'bmtg_pj_create_pages' );
		$this->form_close( __( 'Create missing pages now', 'bmtg-private-journey' ) );
		return (string) ob_get_clean();
	}

	private function settings_roles(): string {
		global $wpdb;
		ob_start();
		echo '<h2>' . esc_html__( 'Role and capability matrix', 'bmtg-private-journey' ) . '</h2>';
		echo '<p>' . esc_html__( 'Administrators hold every capability. The three staff roles are limited to their function.', 'bmtg-private-journey' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'bmtg-private-journey' ) . '</th><th>Admin</th><th>Sales</th><th>Operations</th><th>Finance</th><th>Client</th></tr></thead><tbody>';
		$caps = Capabilities::all();
		$roles = array( 'administrator', Capabilities::ROLE_SALES, Capabilities::ROLE_OPS, Capabilities::ROLE_FIN, Capabilities::ROLE_CLIENT );
		foreach ( $caps as $cap ) {
			echo '<tr><td><code>' . esc_html( $cap ) . '</code></td>';
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				$yes  = $role && $role->has_cap( $cap );
				echo '<td>' . ( $yes ? '✓' : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Client role', 'bmtg-private-journey' ) . '</h2>';
		echo '<p>' . esc_html__( 'Clients can read their own dashboard, enquiries, quotations, documents and profile. Staff roles never see supplier cost or margin unless explicitly granted.', 'bmtg-private-journey' ) . '</p>';
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------- Catalogue */

	public function page_catalogue(): void {
		$this->can( Capabilities::MANAGE_CATALOG );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( 'edit' === $action || 'new' === $action ) {
			$this->page( 'new' === $action ? __( 'Add service', 'bmtg-private-journey' ) : __( 'Edit service', 'bmtg-private-journey' ), $this->catalogue_form( $id ) );
			return;
		}

		global $wpdb;
		$table = Schema::catalogue_items();
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per   = 20;
		$where = array( '1=1' );
		if ( isset( $_GET['type'] ) && '' !== $_GET['type'] ) {
			$where[] = 'item_type = "' . esc_sql( sanitize_key( wp_unslash( $_GET['type'] ) ) ) . '"';
		}
		if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) {
			$where[] = 'status = "' . esc_sql( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '"';
		}
		$where_sql = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ), ARRAY_A );

		ob_start();
		echo '<p>' . $this->link( self::MENU . '-catalogue', __( 'Add service', 'bmtg-private-journey' ), array( 'action' => 'new' ), 'button button-primary' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Type', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Destination', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Featured', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Actions', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><strong>' . esc_html( $row['title'] ) . '</strong></td><td>' . esc_html( ucfirst( $row['item_type'] ) ) . '</td><td>' . esc_html( $row['destination'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . ( $row['featured'] ? '★' : '' ) . '</td><td>';
			echo $this->link( self::MENU . '-catalogue', __( 'Edit', 'bmtg-private-journey' ), array( 'action' => 'edit', 'id' => $row['id'] ), 'button button-small' ) . ' ';
			echo $this->delete_link( 'bmtg_pj_admin_delete_item', (int) $row['id'], self::MENU . '-catalogue' );
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No services yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->paginate( $total, $per, $paged, self::MENU . '-catalogue' );
		$this->page( __( 'Catalogue', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	private function catalogue_form( int $id ): string {
		$item = $id ? Catalogue::instance()->get( $id ) : array();
		if ( $id && ! $item ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Service not found.', 'bmtg-private-journey' ) . '</p></div>';
		}
		$defaults = array(
			'slug' => '', 'item_type' => 'experience', 'title' => '', 'summary' => '', 'description' => '',
			'country' => '', 'destination' => '', 'location' => '', 'category_slug' => '', 'tags' => '',
			'duration' => '', 'price_type' => 'poa', 'price' => '', 'currency' => 'EUR', 'cover_image_id' => 0,
			'gallery' => array(), 'status' => 'draft', 'featured' => 0, 'public_visibility' => 1,
			'facilities' => array(), 'highlights' => array(), 'inclusions' => array(), 'exclusions' => array(),
			'conditions' => '', 'sort_order' => 0,
		);
		$item = array_merge( $defaults, $item );
		$cats = Catalogue::instance()->categories( false );
		$options = $id ? Catalogue::instance()->options_for( $id ) : array();
		$option_lines = array();
		foreach ( $options as $opt ) {
			$option_lines[] = $opt['label'] . ' | ' . ( null === $opt['price'] ? '' : $opt['price'] ) . ' | ' . $opt['price_type'];
		}

		ob_start();
		$this->form_open( 'bmtg_pj_admin_save_item', array( 'id' => $id ) );
		$this->field( __( 'Title', 'bmtg-private-journey' ), $this->input( 'title', $item['title'] ), '', true );
		$this->field( __( 'Slug', 'bmtg-private-journey' ), $this->input( 'slug', $item['slug'] ), __( 'Leave blank to generate from the title.', 'bmtg-private-journey' ) );
		$this->field( __( 'Service type', 'bmtg-private-journey' ), $this->select( 'item_type', array_combine( Schema::item_types(), array_map( 'ucfirst', Schema::item_types() ) ), $item['item_type'] ) );
		$cat_options = array( '' => __( '— None —', 'bmtg-private-journey' ) );
		foreach ( $cats as $cat ) {
			$cat_options[ $cat['slug'] ] = $cat['name'];
		}
		$this->field( __( 'Category', 'bmtg-private-journey' ), $this->select( 'category_slug', $cat_options, $item['category_slug'] ) );
		$this->field( __( 'Summary', 'bmtg-private-journey' ), $this->textarea( 'summary', $item['summary'], 2 ), '', true );
		$this->field( __( 'Long description', 'bmtg-private-journey' ), $this->textarea( 'description', $item['description'], 8 ), '', true );
		$this->field( __( 'Country', 'bmtg-private-journey' ), $this->input( 'country', $item['country'] ) );
		$this->field( __( 'Destination', 'bmtg-private-journey' ), $this->input( 'destination', $item['destination'] ) );
		$this->field( __( 'Location / address', 'bmtg-private-journey' ), $this->input( 'location', $item['location'] ) );
		$this->field( __( 'Tags', 'bmtg-private-journey' ), $this->input( 'tags', is_array( $item['tags'] ) ? implode( ',', $item['tags'] ) : $item['tags'] ), __( 'Comma separated.', 'bmtg-private-journey' ) );
		$this->field( __( 'Duration', 'bmtg-private-journey' ), $this->input( 'duration', $item['duration'] ), __( 'Free text, e.g. 7 nights / 3 hours.', 'bmtg-private-journey' ) );
		$this->field( __( 'Price type', 'bmtg-private-journey' ), $this->select( 'price_type', array( 'poa' => __( 'Price on Request', 'bmtg-private-journey' ), 'fixed' => __( 'Indicative price', 'bmtg-private-journey' ) ), $item['price_type'] ) );
		$this->field( __( 'Price', 'bmtg-private-journey' ), $this->input( 'price', null === $item['price'] ? '' : (string) $item['price'], 'number', array( 'step' => '0.01', 'min' => '0' ) ) );
		$this->field( __( 'Currency', 'bmtg-private-journey' ), $this->input( 'currency', $item['currency'], 'text', array( 'maxlength' => '3' ) ) );
		$this->field( __( 'Cover image', 'bmtg-private-journey' ), $this->media_field( 'cover_image_id', (int) $item['cover_image_id'] ) );
		$this->field( __( 'Gallery image IDs', 'bmtg-private-journey' ), $this->input( 'gallery', implode( ',', array_map( 'strval', (array) $item['gallery'] ) ) ), __( 'Comma separated attachment IDs. The cover image is included automatically.', 'bmtg-private-journey' ), true );
		$this->field( __( 'Status', 'bmtg-private-journey' ), $this->select( 'status', array( 'draft' => __( 'Draft', 'bmtg-private-journey' ), 'published' => __( 'Published', 'bmtg-private-journey' ), 'archived' => __( 'Archived', 'bmtg-private-journey' ) ), $item['status'] ) );
		$this->field( __( 'Featured', 'bmtg-private-journey' ), '<label><input type="checkbox" name="featured" value="1" ' . checked( ! empty( $item['featured'] ), true, false ) . ' /> ' . esc_html__( 'Show in featured collections', 'bmtg-private-journey' ) . '</label>' );
		$this->field( __( 'Public visibility', 'bmtg-private-journey' ), '<label><input type="checkbox" name="public_visibility" value="1" ' . checked( ! empty( $item['public_visibility'] ), true, false ) . ' /> ' . esc_html__( 'Visible in the public catalogue', 'bmtg-private-journey' ) . '</label>' );
		foreach ( array( 'highlights' => __( 'Highlights', 'bmtg-private-journey' ), 'facilities' => __( 'Facilities & features', 'bmtg-private-journey' ), 'inclusions' => __( 'Inclusions', 'bmtg-private-journey' ), 'exclusions' => __( 'Exclusions', 'bmtg-private-journey' ) ) as $key => $label ) {
			$value = is_array( $item[ $key ] ) ? implode( "\n", $item[ $key ] ) : (string) $item[ $key ];
			$this->field( $label, $this->textarea( $key, $value, 4 ), __( 'One per line.', 'bmtg-private-journey' ), true );
		}
		$this->field( __( 'Conditions & restrictions', 'bmtg-private-journey' ), $this->textarea( 'conditions', $item['conditions'], 4 ), '', true );
		$this->field( __( 'Service options', 'bmtg-private-journey' ), $this->textarea( 'options', implode( "\n", $option_lines ), 4 ), __( 'One per line: Label | Price | fixed|poa', 'bmtg-private-journey' ), true );
		$this->field( __( 'Sort order', 'bmtg-private-journey' ), $this->input( 'sort_order', (string) $item['sort_order'], 'number' ) );
		$this->form_close( __( 'Save service', 'bmtg-private-journey' ) );
		return (string) ob_get_clean();
	}

	public function save_item(): void {
		$this->can( Capabilities::MANAGE_CATALOG );
		$this->verify( 'bmtg_pj_admin_save_item' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$options = array();
		if ( ! empty( $_POST['options'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['options'] ) ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( empty( $parts[0] ) ) {
					continue;
				}
				$options[] = array(
					'label'      => $parts[0],
					'price'      => isset( $parts[1] ) && is_numeric( $parts[1] ) ? (float) $parts[1] : null,
					'price_type' => isset( $parts[2] ) && 'fixed' === $parts[2] ? 'fixed' : 'poa',
				);
			}
		}

		$data = array(
			'slug'             => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'item_type'        => isset( $_POST['item_type'] ) ? sanitize_key( wp_unslash( $_POST['item_type'] ) ) : 'experience',
			'title'            => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'summary'          => isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '',
			'description'      => isset( $_POST['description'] ) ? wp_kses_post( (string) wp_unslash( $_POST['description'] ) ) : '',
			'country'          => isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
			'destination'      => isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '',
			'location'         => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '',
			'category_slug'    => isset( $_POST['category_slug'] ) ? sanitize_title( wp_unslash( $_POST['category_slug'] ) ) : '',
			'tags'             => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
			'duration'         => isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : '',
			'price_type'       => isset( $_POST['price_type'] ) && 'fixed' === $_POST['price_type'] ? 'fixed' : 'poa',
			'price'            => isset( $_POST['price'] ) && is_numeric( $_POST['price'] ) ? (float) $_POST['price'] : null,
			'currency'         => isset( $_POST['currency'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['currency'] ) ), 0, 3 ) ) : 'EUR',
			'cover_image_id'   => isset( $_POST['cover_image_id'] ) ? (int) $_POST['cover_image_id'] : 0,
			'gallery'          => isset( $_POST['gallery'] ) ? array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_POST['gallery'] ) ) ) ) : array(),
			'status'           => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
			'featured'         => empty( $_POST['featured'] ) ? 0 : 1,
			'public_visibility' => empty( $_POST['public_visibility'] ) ? 0 : 1,
			'facilities'       => isset( $_POST['facilities'] ) ? (string) wp_unslash( $_POST['facilities'] ) : '',
			'highlights'       => isset( $_POST['highlights'] ) ? (string) wp_unslash( $_POST['highlights'] ) : '',
			'inclusions'       => isset( $_POST['inclusions'] ) ? (string) wp_unslash( $_POST['inclusions'] ) : '',
			'exclusions'       => isset( $_POST['exclusions'] ) ? (string) wp_unslash( $_POST['exclusions'] ) : '',
			'conditions'       => isset( $_POST['conditions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['conditions'] ) ) : '',
			'sort_order'       => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'options'          => $options,
		);

		if ( $id ) {
			Catalogue::instance()->update( $id, $data );
			Audit::instance()->log( 'catalogue.update', 'catalogue_item', $id );
			$this->redirect( self::MENU . '-catalogue', array( 'pj_notice' => __( 'Service updated.', 'bmtg-private-journey' ) ) );
		}

		$new_id = Catalogue::instance()->create( $data );
		Audit::instance()->log( 'catalogue.create', 'catalogue_item', $new_id );
		$this->redirect( self::MENU . '-catalogue', array( 'pj_notice' => __( 'Service created.', 'bmtg-private-journey' ) ) );
	}

	public function delete_item(): void {
		$this->can( Capabilities::MANAGE_CATALOG );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$this->verify( 'bmtg_pj_admin_delete_item_' . $id );
		Catalogue::instance()->delete( $id );
		Audit::instance()->log( 'catalogue.delete', 'catalogue_item', $id );
		$this->redirect( self::MENU . '-catalogue', array( 'pj_notice' => __( 'Service deleted.', 'bmtg-private-journey' ) ) );
	}

	/* -------------------------------------------------------------- Suppliers */

	public function page_suppliers(): void {
		$this->can( Capabilities::MANAGE_SUPPLIERS );
		global $wpdb;
		$table = Schema::suppliers();
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) : array();
		$row   = array_merge( array( 'name' => '', 'supplier_type' => '', 'contact_name' => '', 'email' => '', 'phone' => '', 'notes' => '', 'status' => 'active' ), (array) $row );

		ob_start();
		$this->form_open( 'bmtg_pj_admin_save_supplier', array( 'id' => $id ) );
		$this->field( __( 'Supplier name', 'bmtg-private-journey' ), $this->input( 'name', $row['name'] ), '', true );
		$this->field( __( 'Type', 'bmtg-private-journey' ), $this->input( 'supplier_type', $row['supplier_type'] ) );
		$this->field( __( 'Contact name', 'bmtg-private-journey' ), $this->input( 'contact_name', $row['contact_name'] ) );
		$this->field( __( 'Email', 'bmtg-private-journey' ), $this->input( 'email', $row['email'], 'email' ) );
		$this->field( __( 'Phone', 'bmtg-private-journey' ), $this->input( 'phone', $row['phone'] ) );
		$this->field( __( 'Status', 'bmtg-private-journey' ), $this->select( 'status', array( 'active' => __( 'Active', 'bmtg-private-journey' ), 'inactive' => __( 'Inactive', 'bmtg-private-journey' ) ), $row['status'] ) );
		$this->field( __( 'Notes', 'bmtg-private-journey' ), $this->textarea( 'notes', $row['notes'], 4 ), '', true );
		$this->form_close( $id ? __( 'Update supplier', 'bmtg-private-journey' ) : __( 'Add supplier', 'bmtg-private-journey' ) );

		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC", ARRAY_A );
		echo '<h2>' . esc_html__( 'Supplier directory', 'bmtg-private-journey' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Type', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Email', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			echo '<tr><td><strong>' . esc_html( $s['name'] ) . '</strong></td><td>' . esc_html( $s['supplier_type'] ) . '</td><td>' . esc_html( $s['email'] ) . '</td><td>' . esc_html( $s['status'] ) . '</td><td>';
			echo $this->link( self::MENU . '-suppliers', __( 'Edit', 'bmtg-private-journey' ), array( 'id' => $s['id'] ), 'button button-small' ) . ' ';
			echo $this->delete_link( 'bmtg_pj_admin_delete_supplier', (int) $s['id'], self::MENU . '-suppliers' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->page( __( 'Suppliers', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	public function save_supplier(): void {
		$this->can( Capabilities::MANAGE_SUPPLIERS );
		$this->verify( 'bmtg_pj_admin_save_supplier' );
		global $wpdb;
		$table = Schema::suppliers();
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$data  = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'supplier_type' => sanitize_text_field( wp_unslash( $_POST['supplier_type'] ?? '' ) ),
			'contact_name'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
			'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'        => isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ? 'inactive' : 'active',
			'updated_at'    => Schema::now(),
		);
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = Schema::now();
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		Audit::instance()->log( 'supplier.save', 'supplier', $id );
		$this->redirect( self::MENU . '-suppliers', array( 'pj_notice' => __( 'Supplier saved.', 'bmtg-private-journey' ) ) );
	}

	public function delete_supplier(): void {
		$this->can( Capabilities::MANAGE_SUPPLIERS );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$this->verify( 'bmtg_pj_admin_delete_supplier_' . $id );
		global $wpdb;
		$wpdb->delete( Schema::suppliers(), array( 'id' => $id ) );
		$this->redirect( self::MENU . '-suppliers', array( 'pj_notice' => __( 'Supplier deleted.', 'bmtg-private-journey' ) ) );
	}

	/* --------------------------------------------------------------- Requests */

	public function page_requests(): void {
		$this->can( Capabilities::VIEW_REQUESTS );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( 'edit' === $action && $id ) {
			$this->page( __( 'Journey request', 'bmtg-private-journey' ), $this->request_detail( $id ) );
			return;
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$result = Enquiry::instance()->list( array( 'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', 'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', 'page' => $paged, 'per_page' => 20 ) );

		ob_start();
		echo '<p>' . $this->link( self::MENU . '-quotes', __( 'Open quotations', 'bmtg-private-journey' ), array(), 'button' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Reference', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Client', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Destinations', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Dates', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Guests', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $result['items'] as $row ) {
			echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => self::MENU . '-requests', 'action' => 'edit', 'id' => $row['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $row['reference'] ) . '</a></td><td>' . esc_html( $row['name'] ) . '<br><small>' . esc_html( $row['email'] ) . '</small></td><td>' . esc_html( $row['destinations'] ) . '</td><td>' . esc_html( ( $row['arrival_date'] ?: '—' ) . ' → ' . ( $row['departure_date'] ?: '—' ) ) . '</td><td>' . esc_html( $row['adults'] . ' / ' . $row['children'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . $this->link( self::MENU . '-requests', __( 'Review', 'bmtg-private-journey' ), array( 'action' => 'edit', 'id' => $row['id'] ), 'button button-small' ) . '</td></tr>';
		}
		if ( ! $result['items'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No requests yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->paginate( $result['total'], 20, $paged, self::MENU . '-requests' );
		$this->page( __( 'Journey requests', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	private function request_detail( int $id ): string {
		$enquiry = Enquiry::instance()->get( $id );
		if ( ! $enquiry ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Request not found.', 'bmtg-private-journey' ) . '</p></div>';
		}
		$items   = Enquiry::instance()->items( $id );
		$quotes  = Quotation::instance()->latest_for_enquiry( $id );
		$clients = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 100 ) );
		$assignees = array( '' => __( '— Unassigned —', 'bmtg-private-journey' ) );
		foreach ( $clients as $u ) {
			$assignees[ (int) $u->ID ] = $u->display_name;
		}

		ob_start();
		echo '<p>' . $this->link( self::MENU . '-requests', '← ' . __( 'Back to requests', 'bmtg-private-journey' ) ) . '</p>';
		echo '<div class="pj-detail-grid"><div>';
		echo '<h2>' . esc_html( $enquiry['reference'] ) . '</h2>';
		echo '<p><strong>' . esc_html( $enquiry['name'] ) . '</strong> · ' . esc_html( $enquiry['email'] ) . ' · ' . esc_html( $enquiry['phone'] ) . '</p>';
		echo '<p>' . esc_html( sprintf( __( '%1$s → %2$s · %3$d adults, %4$d children · Budget: %5$s', 'bmtg-private-journey' ), $enquiry['arrival_date'] ?: '—', $enquiry['departure_date'] ?: '—', (int) $enquiry['adults'], (int) $enquiry['children'], $enquiry['budget'] ?: '—' ) ) . '</p>';
		echo '<p>' . esc_html( $enquiry['destinations'] ) . '</p>';
		if ( $enquiry['preferences'] ) {
			echo '<h3>' . esc_html__( 'Preferences', 'bmtg-private-journey' ) . '</h3><p>' . nl2br( esc_html( $enquiry['preferences'] ) ) . '</p>';
		}
		if ( $enquiry['special_requests'] ) {
			echo '<h3>' . esc_html__( 'Special requests', 'bmtg-private-journey' ) . '</h3><p>' . nl2br( esc_html( $enquiry['special_requests'] ) ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Selected services', 'bmtg-private-journey' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Type', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Qty', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Details', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$meta = Schema::decode( $item['meta'] ?? '', array() );
			$detail = trim( ( $meta['start_date'] ?? '' ) . ' ' . ( $meta['end_date'] ?? '' ) . ' ' . ( $meta['pickup'] ?? '' ) . ' ' . ( $meta['dropoff'] ?? '' ) . ' ' . ( $meta['flight_number'] ?? '' ) );
			echo '<tr><td>' . esc_html( $item['description'] ) . '</td><td>' . esc_html( $item['service_type'] ) . '</td><td>' . esc_html( $item['quantity'] ) . '</td><td>' . esc_html( $detail ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$this->form_open( 'bmtg_pj_admin_update_request', array( 'id' => $id ) );
		$this->field( __( 'Status', 'bmtg-private-journey' ), $this->select( 'status', array_combine( Schema::enquiry_statuses(), array_map( 'ucfirst', Schema::enquiry_statuses() ) ), $enquiry['status'] ) );
		$this->field( __( 'Assigned to', 'bmtg-private-journey' ), $this->select( 'assigned_to', $assignees, (string) $enquiry['assigned_to'] ) );
		$this->field( __( 'Internal notes (never shown to client)', 'bmtg-private-journey' ), $this->textarea( 'internal_notes', $enquiry['internal_notes'], 6 ), '', true );
		$this->form_close( __( 'Update request', 'bmtg-private-journey' ) );

		if ( $quotes ) {
			$totals = $quotes['totals'];
			echo '<h3>' . esc_html( sprintf( __( 'Latest proposal v%d', 'bmtg-private-journey' ), (int) $quotes['version'] ) ) . '</h3>';
			echo '<p>' . esc_html( sprintf( __( 'Status: %1$s · Sell total: %2$s · Cost: %3$s · Margin: %4$s', 'bmtg-private-journey' ), $quotes['status'], Engine::format( $totals['total'] ?? 0, $totals['currency'] ?? 'EUR' ), Engine::format( $totals['cost'] ?? 0, $totals['currency'] ?? 'EUR' ), Engine::format( $totals['margin'] ?? 0, $totals['currency'] ?? 'EUR' ) ) ) . '</p>';
		}

		echo '</div><div>';
		echo '<div class="pj-side-card"><h2>' . esc_html__( 'Quotation', 'bmtg-private-journey' ) . '</h2>';
		echo '<p>' . $this->link( self::MENU . '-quotes', __( 'Prepare or edit proposal', 'bmtg-private-journey' ), array( 'action' => 'new', 'enquiry_id' => $id ), 'button button-primary' ) . '</p>';
		if ( $quotes ) {
			echo '<p>' . $this->link( self::MENU . '-quotes', __( 'Edit latest proposal', 'bmtg-private-journey' ), array( 'action' => 'edit', 'id' => $quotes['id'] ), 'button' ) . '</p>';
		}
		echo '</div>';
		echo '<div class="pj-side-card"><h2>' . esc_html__( 'Documents', 'bmtg-private-journey' ) . '</h2><p>' . $this->link( self::MENU . '-docs', __( 'Manage documents', 'bmtg-private-journey' ) ) . '</p></div>';
		echo '</div></div>';

		return (string) ob_get_clean();
	}

	public function update_request(): void {
		$this->can( Capabilities::MANAGE_REQUESTS );
		$this->verify( 'bmtg_pj_admin_update_request' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			$this->redirect( self::MENU . '-requests', array( 'pj_error' => __( 'Request not found.', 'bmtg-private-journey' ) ) );
		}
		Enquiry::instance()->set_status( $id, isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'new' );
		Enquiry::instance()->assign( $id, isset( $_POST['assigned_to'] ) ? (int) $_POST['assigned_to'] : null );
		Enquiry::instance()->save_internal_note( $id, isset( $_POST['internal_notes'] ) ? (string) wp_unslash( $_POST['internal_notes'] ) : '' );
		$this->redirect( self::MENU . '-requests', array( 'action' => 'edit', 'id' => $id, 'pj_notice' => __( 'Request updated.', 'bmtg-private-journey' ) ) );
	}

	/* ------------------------------------------------------------- Quotations */

	public function page_quotes(): void {
		$this->can( Capabilities::MANAGE_QUOTES );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( 'edit' === $action ) {
			$quote = $id ? Quotation::instance()->get( $id ) : null;
			if ( $id && ! $quote ) {
				$this->page( __( 'Quotations', 'bmtg-private-journey' ), '<div class="notice notice-error"><p>' . esc_html__( 'Quotation not found.', 'bmtg-private-journey' ) . '</p></div>' );
				return;
			}
			$this->page( __( 'Quotation', 'bmtg-private-journey' ), $this->quote_form( $quote ) );
			return;
		}
		if ( 'new' === $action ) {
			$enquiry_id = isset( $_GET['enquiry_id'] ) ? (int) $_GET['enquiry_id'] : 0;
			$enquiry    = $enquiry_id ? Enquiry::instance()->get( $enquiry_id ) : null;
			if ( ! $enquiry ) {
				$this->page( __( 'Quotations', 'bmtg-private-journey' ), '<div class="notice notice-error"><p>' . esc_html__( 'Select a journey request first.', 'bmtg-private-journey' ) . '</p></div>' );
				return;
			}
			$this->page( __( 'New quotation', 'bmtg-private-journey' ), $this->quote_form( null, $enquiry ) );
			return;
		}

		global $wpdb;
		$table = Schema::quotation_versions();
		$rows  = $wpdb->get_results( "SELECT q.*, e.reference, e.name FROM $table q LEFT JOIN " . Schema::enquiries() . " e ON e.id = q.enquiry_id ORDER BY q.id DESC LIMIT 100", ARRAY_A );
		ob_start();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Enquiry', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Client', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Version', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Total', 'bmtg-private-journey' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$totals = Schema::decode( $row['totals'] ?? '', array() );
			echo '<tr><td>' . esc_html( $row['reference'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['version'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( Engine::format( $totals['total'] ?? 0, $totals['currency'] ?? 'EUR' ) ) . '</td><td>';
			echo $this->link( self::MENU . '-quotes', __( 'Edit', 'bmtg-private-journey' ), array( 'action' => 'edit', 'id' => $row['id'] ), 'button button-small' );
			if ( 'draft' === $row['status'] && Capabilities::can_issue_quotes() ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'bmtg_pj_admin_quote_issue', 'id' => $row['id'] ), admin_url( 'admin-post.php' ) ), 'bmtg_pj_admin_quote_issue_' . $row['id'] );
				echo ' <a class="button button-primary button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Issue', 'bmtg-private-journey' ) . '</a>';
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No quotations yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->page( __( 'Quotations', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	private function quote_form( ?array $quote, ?array $enquiry = null ): string {
		$enquiry_id = $quote ? (int) $quote['enquiry_id'] : ( $enquiry ? (int) $enquiry['id'] : 0 );
		$enquiry    = $enquiry ?: Enquiry::instance()->get( $enquiry_id );
		if ( ! $enquiry ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Enquiry not found.', 'bmtg-private-journey' ) . '</p></div>';
		}

		if ( $quote && $quote['status'] !== 'draft' ) {
			// Re-opening an issued quote creates a new draft version, never edits history.
			$quote = null;
		}

		$lines = $quote ? $quote['items'] : Enquiry::instance()->items( $enquiry_id );
		$totals = $quote ? $quote['totals'] : array( 'discount_type' => 'none', 'discount' => 0, 'tax_rate' => 0, 'currency' => $enquiry['currency'] );

		ob_start();
		echo '<h2>' . esc_html( sprintf( __( '%s — proposal', 'bmtg-private-journey' ), $enquiry['reference'] ) ) . '</h2>';
		$this->form_open( 'bmtg_pj_admin_quote_save', array( 'enquiry_id' => $enquiry_id ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Description', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Qty', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Unit cost', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Unit selling price', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $lines as $index => $line ) {
			echo '<tr>';
			echo '<td>' . esc_html( $line['description'] ) . '</td>';
			echo '<td>' . esc_html( $line['quantity'] ) . '</td>';
			echo '<td><input type="number" step="0.01" min="0" name="cost[' . esc_attr( $index ) . ']" value="' . esc_attr( null === $line['unit_cost'] ? '' : $line['unit_cost'] ) . '" /></td>';
			echo '<td><input type="number" step="0.01" min="0" name="price[' . esc_attr( $index ) . ']" value="' . esc_attr( null === $line['unit_price'] ? '' : $line['unit_price'] ) . '" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->field( __( 'Discount type', 'bmtg-private-journey' ), $this->select( 'discount_type', array( 'none' => __( 'None', 'bmtg-private-journey' ), 'fixed' => __( 'Fixed amount', 'bmtg-private-journey' ), 'percent' => __( 'Percentage', 'bmtg-private-journey' ) ), $totals['discount_type'] ?? 'none' ) );
		$this->field( __( 'Discount value', 'bmtg-private-journey' ), $this->input( 'discount', (string) ( $totals['discount'] ?? 0 ), 'number', array( 'step' => '0.01', 'min' => '0' ) ) );
		$this->field( __( 'Tax rate (0–1, e.g. 0.10)', 'bmtg-private-journey' ), $this->input( 'tax_rate', (string) ( $totals['tax_rate'] ?? 0 ), 'number', array( 'step' => '0.001', 'min' => '0' ) ) );
		$this->field( __( 'Currency', 'bmtg-private-journey' ), $this->input( 'currency', $totals['currency'] ?? 'EUR', 'text', array( 'maxlength' => '3' ) ) );
		$this->field( __( 'Notes shown to client', 'bmtg-private-journey' ), $this->textarea( 'notes', $quote['notes'] ?? '', 4 ), '', true );
		$this->form_close( __( 'Save proposal', 'bmtg-private-journey' ) );

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Supplier cost and margin are stored server-side and are never rendered to the client. An item with no selling price remains Price on Request.', 'bmtg-private-journey' ) . '</p></div>';
		return (string) ob_get_clean();
	}

	public function save_quote(): void {
		$this->can( Capabilities::MANAGE_QUOTES );
		$this->verify( 'bmtg_pj_admin_quote_save' );
		$enquiry_id = isset( $_POST['enquiry_id'] ) ? (int) $_POST['enquiry_id'] : 0;
		$enquiry    = Enquiry::instance()->get( $enquiry_id );
		if ( ! $enquiry ) {
			$this->redirect( self::MENU . '-quotes', array( 'pj_error' => __( 'Enquiry not found.', 'bmtg-private-journey' ) ) );
		}

		$items = Enquiry::instance()->items( $enquiry_id );
		$lines = array();
		foreach ( $items as $index => $item ) {
			$cost  = isset( $_POST['cost'][ $index ] ) && '' !== $_POST['cost'][ $index ] && is_numeric( $_POST['cost'][ $index ] ) ? (float) $_POST['cost'][ $index ] : null;
			$price = isset( $_POST['price'][ $index ] ) && '' !== $_POST['price'][ $index ] && is_numeric( $_POST['price'][ $index ] ) ? (float) $_POST['price'][ $index ] : null;
			$lines[] = array(
				'description' => $item['description'],
				'quantity'    => (int) $item['quantity'],
				'unit_cost'   => $cost,
				'unit_price'  => $price,
				'item_type'   => $item['service_type'],
				'item_id'     => (int) $item['item_id'],
				'meta'        => Schema::decode( $item['meta'] ?? '', array() ),
			);
		}

		$result = Quotation::instance()->create(
			$enquiry_id,
			$lines,
			array(
				'discount_type' => isset( $_POST['discount_type'] ) ? sanitize_key( wp_unslash( $_POST['discount_type'] ) ) : 'none',
				'discount'      => isset( $_POST['discount'] ) ? (float) $_POST['discount'] : 0,
				'tax_rate'      => isset( $_POST['tax_rate'] ) ? (float) $_POST['tax_rate'] : 0,
				'currency'      => isset( $_POST['currency'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['currency'] ) ), 0, 3 ) ) : 'EUR',
				'notes'         => isset( $_POST['notes'] ) ? (string) wp_unslash( $_POST['notes'] ) : '',
			)
		);

		if ( ! $result['ok'] ) {
			$this->redirect( self::MENU . '-quotes', array( 'pj_error' => $result['error'] ) );
		}
		$this->redirect( self::MENU . '-quotes', array( 'action' => 'edit', 'id' => $result['id'], 'pj_notice' => __( 'Proposal saved as a new version.', 'bmtg-private-journey' ) ) );
	}

	public function issue_quote(): void {
		$this->can( Capabilities::ISSUE_QUOTES );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$this->verify( 'bmtg_pj_admin_quote_issue_' . $id );
		$result = Quotation::instance()->issue( $id );
		$this->redirect( self::MENU . '-quotes', array( 'pj_notice' => $result['ok'] ? __( 'Proposal issued.', 'bmtg-private-journey' ) : ( $result['error'] ?? '' ) ) );
	}

	/* ---------------------------------------------------------------- Clients */

	public function page_clients(): void {
		$this->can( Capabilities::VIEW_REQUESTS );
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$result = Clients::instance()->list_clients( array( 'page' => $paged, 'per_page' => 25, 'search' => $search ) );

		ob_start();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Email', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Phone', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Since', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $result['items'] as $client ) {
			echo '<tr><td><strong>' . esc_html( $client['full_name'] ) . '</strong></td><td>' . esc_html( $client['email'] ) . '</td><td>' . esc_html( $client['phone'] ) . '</td><td>' . esc_html( $client['status'] ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $client['created_at'] ) ) . '</td></tr>';
		}
		if ( ! $result['items'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No clients yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->paginate( $result['total'], 25, $paged, self::MENU . '-clients', array( 's' => $search ) );
		$this->page( __( 'Clients', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	/* -------------------------------------------------------------- Documents */

	public function page_documents(): void {
		$this->can( Capabilities::MANAGE_DOCS );
		global $wpdb;
		$table  = Schema::documents();
		$rows   = $wpdb->get_results( "SELECT d.*, c.email FROM $table d LEFT JOIN " . Schema::clients() . " c ON c.id = d.client_id WHERE d.status = 'active' ORDER BY d.id DESC LIMIT 100", ARRAY_A );
		$clients = Clients::instance()->list_clients( array( 'per_page' => 200 ) );

		ob_start();
		$this->form_open( 'bmtg_pj_admin_upload_doc', array() );
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">';
		echo '<div><label><strong>' . esc_html__( 'Client', 'bmtg-private-journey' ) . '</strong></label><br><select name="client_id"><option value="0">' . esc_html__( '— Unassigned —', 'bmtg-private-journey' ) . '</option>';
		foreach ( $clients['items'] as $client ) {
			echo '<option value="' . esc_attr( $client['id'] ) . '">' . esc_html( $client['full_name'] . ' · ' . $client['email'] ) . '</option>';
		}
		echo '</select></div>';
		echo '<div><label><strong>' . esc_html__( 'Title', 'bmtg-private-journey' ) . '</strong></label><br>' . $this->input( 'title', '' ) . '</div>';
		echo '<div><label><strong>' . esc_html__( 'File', 'bmtg-private-journey' ) . '</strong></label><br><input type="file" name="document" required /></div>';
		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Upload document', 'bmtg-private-journey' ) . '</button></div>';
		echo '</div></form>';
		echo '<p class="description">' . esc_html__( 'Files are stored outside the public uploads directory and served only through authenticated download links.', 'bmtg-private-journey' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Client', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'File', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Size', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Uploaded', 'bmtg-private-journey' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><strong>' . esc_html( $row['title'] ) . '</strong></td><td>' . esc_html( $row['email'] ?: '—' ) . '</td><td>' . esc_html( $row['file_name'] ) . '</td><td>' . esc_html( size_format( (int) $row['size'] ) ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $row['created_at'] ) ) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url( Documents::instance()->download_url( (int) $row['id'] ) ) . '">' . esc_html__( 'Download', 'bmtg-private-journey' ) . '</a> ';
			echo $this->delete_link( 'bmtg_pj_admin_delete_doc', (int) $row['id'], self::MENU . '-docs' );
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No documents yet.', 'bmtg-private-journey' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->page( __( 'Documents', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	public function upload_doc(): void {
		$this->can( Capabilities::MANAGE_DOCS );
		$this->verify( 'bmtg_pj_admin_upload_doc' );
		$client_id = isset( $_POST['client_id'] ) ? (int) $_POST['client_id'] : 0;
		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$id        = Documents::instance()->store( $client_id, $title, $_FILES['document'] ?? array() );
		Audit::instance()->log( 'document.upload', 'document', $id );
		$this->redirect( self::MENU . '-docs', array( 'pj_notice' => $id ? __( 'Document uploaded.', 'bmtg-private-journey' ) : __( 'Upload failed. Check file type and size.', 'bmtg-private-journey' ) ) );
	}

	public function delete_doc(): void {
		$this->can( Capabilities::MANAGE_DOCS );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$this->verify( 'bmtg_pj_admin_delete_doc_' . $id );
		Documents::instance()->delete( $id );
		$this->redirect( self::MENU . '-docs', array( 'pj_notice' => __( 'Document removed.', 'bmtg-private-journey' ) ) );
	}

	/* ---------------------------------------------------------------- Reports */

	public function page_reports(): void {
		$this->can( Capabilities::VIEW_REPORTS );
		global $wpdb;
		$enq   = Schema::enquiries();
		$quote = Schema::quotation_versions();
		$conf  = Schema::confirmed_journeys();
		$cli   = Schema::clients();

		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $enq GROUP BY status", ARRAY_A );
		$issued    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quote WHERE status = 'issued'" );
		$accepted  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quote WHERE status = 'accepted'" );
		$revenue   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(sell_price),0) FROM $enq WHERE status IN ('accepted','confirmed','closed')" );
		$cost      = (float) $wpdb->get_var( "SELECT COALESCE(SUM(supplier_cost_total),0) FROM $enq WHERE status IN ('accepted','confirmed','closed')" );
		$margin    = $revenue - $cost;

		ob_start();
		echo '<div class="pj-cards">';
		echo '<div class="pj-card-stat"><strong>' . esc_html( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cli" ) ) . '</strong><span>' . esc_html__( 'Clients', 'bmtg-private-journey' ) . '</span></div>';
		echo '<div class="pj-card-stat"><strong>' . esc_html( $issued ) . '</strong><span>' . esc_html__( 'Proposals issued', 'bmtg-private-journey' ) . '</span></div>';
		echo '<div class="pj-card-stat"><strong>' . esc_html( $accepted ) . '</strong><span>' . esc_html__( 'Accepted', 'bmtg-private-journey' ) . '</span></div>';
		echo '<div class="pj-card-stat"><strong>' . esc_html( Engine::format( $revenue, (string) Settings::get( 'general', 'currency', 'EUR' ) ) ) . '</strong><span>' . esc_html__( 'Selling value', 'bmtg-private-journey' ) . '</span></div>';
		echo '<div class="pj-card-stat"><strong>' . esc_html( Engine::format( $margin, (string) Settings::get( 'general', 'currency', 'EUR' ) ) ) . '</strong><span>' . esc_html__( 'Gross margin', 'bmtg-private-journey' ) . '</span></div>';
		echo '<div class="pj-card-stat"><strong>' . esc_html( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $conf" ) ) . '</strong><span>' . esc_html__( 'Confirmed journeys', 'bmtg-private-journey' ) . '</span></div>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Requests by stage', 'bmtg-private-journey' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Status', 'bmtg-private-journey' ) . '</th><th>' . esc_html__( 'Count', 'bmtg-private-journey' ) . '</th></tr></thead><tbody>';
		foreach ( $by_status as $row ) {
			echo '<tr><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['c'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Revenue and margin are calculated from stored selling prices and supplier costs only. Supplier rates and margins are visible only to authorised staff.', 'bmtg-private-journey' ) . '</p>';
		$this->page( __( 'Reports', 'bmtg-private-journey' ), (string) ob_get_clean() );
	}

	/* ----------------------------------------------------------- Settings I/O */

	public function save_settings(): void {
		$this->can( Capabilities::MANAGE_SETTINGS );
		$this->verify( 'bmtg_pj_settings' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'brand';

		if ( 'brand' === $tab ) {
			// The Brand tab submits both company[...] and brand[...] fields.
			$company_input = isset( $_POST['company'] ) && is_array( $_POST['company'] ) ? wp_unslash( $_POST['company'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$brand_input   = isset( $_POST['brand'] ) && is_array( $_POST['brand'] ) ? wp_unslash( $_POST['brand'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Settings::update( array( 'company' => Settings::sanitize_section( 'company', $company_input ) ) );
			Settings::update( array( 'brand' => Settings::sanitize_section( 'brand', $brand_input ) ) );
		} else {
			$input = isset( $_POST[ $tab ] ) && is_array( $_POST[ $tab ] ) ? wp_unslash( $_POST[ $tab ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Settings::update( array( $tab => Settings::sanitize_section( $tab, $input ) ) );
		}

		Brand::flush();
		Audit::instance()->log( 'settings.update', 'settings', 0, array( 'tab' => $tab ) );
		$this->redirect( self::MENU . '-settings', array( 'tab' => $tab, 'pj_notice' => __( 'Settings saved.', 'bmtg-private-journey' ) ) );
	}

	public function save_pages(): void {
		$this->can( Capabilities::MANAGE_SETTINGS );
		$this->verify( 'bmtg_pj_pages' );
		$mapping = isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? array_map( 'absint', wp_unslash( $_POST['pages'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Pages::set( $mapping );
		$this->redirect( self::MENU . '-settings', array( 'tab' => 'pages', 'pj_notice' => __( 'Page mapping saved.', 'bmtg-private-journey' ) ) );
	}

	public function create_pages(): void {
		$this->can( Capabilities::MANAGE_SETTINGS );
		$this->verify( 'bmtg_pj_create_pages' );
		$result = Pages::create_missing();
		Audit::instance()->log( 'pages.create', 'pages', 0, array( 'created' => $result['created'] ) );
		$notice = sprintf( __( '%1$d pages created, %2$d already mapped.', 'bmtg-private-journey' ), $result['created'], $result['skipped'] );
		$this->redirect( self::MENU . '-settings', array( 'tab' => 'pages', 'pj_notice' => $notice ) );
	}
}